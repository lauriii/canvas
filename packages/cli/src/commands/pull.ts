import fs from 'fs/promises';
import path from 'path';
import { Option } from 'commander';
import yaml from 'js-yaml';
import { parse } from '@babel/parser';
import * as p from '@clack/prompts';
import { discoverCanvasProject } from '@drupal-canvas/discovery';
import {
  resolveHostGlobalCssPath,
  SITE_IMPORT_MAP_FILE,
  writeSiteImportMap,
} from '@drupal-canvas/vite-compat';

import { ensureConfig, getConfig } from '../config';
import {
  buildExistingVariantKeys,
  pullFonts,
  readBrandKitConfig,
  updateBrandKitConfig,
  variantKey,
} from '../lib/fonts/font-pull.js';
import { createApiService, ensureAuthConfig } from '../services/api';
import {
  applySyncOptionAliasesAndWarnings,
  parseBooleanOption,
  pluralizeComponent,
  updateConfigFromOptions,
} from '../utils/command-helpers';
import { printCommandIntro } from '../utils/command-intro';
import { appendCommandSummarySection } from '../utils/command-summary';
import { contentTemplateToAuthored } from '../utils/content-templates';
import { ensureTailwindImportAtTop } from '../utils/ensure-global-css-tailwind-import';
import { pageVariantToAuthoredSpec } from '../utils/page-variants';
import { pageToAuthoredSpec } from '../utils/pages';
import {
  COMMAND_RESULT_REPORT_OPTIONS,
  reportResults,
} from '../utils/report-results';

import type {
  DiscoveredComponent,
  DiscoveredContentTemplate,
  DiscoveredPage,
  DiscoveredPageTemplate,
} from '@drupal-canvas/discovery';
import type {
  AssetLibraryBundledSource,
  AssetLibraryManifestEntry,
} from '@drupal-canvas/ui/types/CodeComponent';
import type { ImportMap } from '@drupal-canvas/vite-compat';
import type { Command } from 'commander';
import type { ApiService } from '../services/api';
import type { Component } from '../types/Component';
import type { ContentTemplateListItem } from '../types/ContentTemplate';
import type { Metadata } from '../types/Metadata';
import type { PageListItem } from '../types/Page';
import type { PageVariant } from '../types/PageVariant';
import type { Result } from '../types/Result';
import type { CommandSummaryResource } from '../utils/command-summary';

interface PullOptions {
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  scope?: string;
  includePages?: boolean;
  includeContentTemplates?: boolean;
  pages?: boolean;
  contentTemplates?: boolean;
  pageTemplates?: boolean;
  includeBrandKit?: boolean;
  dir?: string;
  yes?: boolean;
  skipOverwrite?: boolean;
}

export interface PullTaskPrepareResult {
  summaryLines: string[];
  localOnlyCount: number;
}

export interface PullTask {
  startLabel: string;
  stopLabel: string;
  prepare(): Promise<PullTaskPrepareResult>;
  execute(options?: { deleteLocalOnly?: boolean }): Promise<PullTaskResult>;
}

export interface PullTaskResult {
  results: Result[];
  title: string;
  label: string;
  notes?: string[];
}

function pluralizeLabel(count: number, singular: string, plural?: string) {
  return count === 1 ? singular : (plural ?? `${singular}s`);
}

function formatSummaryLine(
  groupLabel: string,
  total: number,
  newCount: number,
  existingCount: number,
  unit?: string,
  unitPlural?: string,
): string {
  const count = unit
    ? `${total} ${pluralizeLabel(total, unit, unitPlural)}`
    : String(total);
  const details: string[] = [];
  if (newCount > 0) details.push(`${newCount} new`);
  if (existingCount > 0) details.push(`${existingCount} existing`);
  const suffix = details.length > 0 ? ` (${details.join(', ')})` : '';
  return `${groupLabel}: ${count} pull${suffix}`;
}

function formatPullPlan(summaryLines: string[]): string {
  return ['Plan', ...summaryLines].join('\n');
}

function formatErrorMessage(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}

function pullFailureItemName(message: string): string {
  return message.includes('Authentication Error') ||
    message.includes('Authentication failed')
    ? 'Authentication failed'
    : 'Pull failed';
}

function isWithinDirectory(directory: string, candidate: string): boolean {
  const relative = path.relative(directory, candidate);
  return (
    relative === '' ||
    (!relative.startsWith(`..${path.sep}`) &&
      relative !== '..' &&
      !path.isAbsolute(relative))
  );
}

async function resolveAssetPullDestination(
  projectRoot: string,
  relativePath: string,
): Promise<string> {
  const destination = path.resolve(projectRoot, relativePath);
  if (
    destination === projectRoot ||
    !isWithinDirectory(projectRoot, destination)
  ) {
    throw new Error(
      `File "${relativePath}" resolves outside the project root.`,
    );
  }

  let currentPath = projectRoot;
  for (const segment of path
    .relative(projectRoot, destination)
    .split(path.sep)) {
    currentPath = path.join(currentPath, segment);
    let stats;
    try {
      stats = await fs.lstat(currentPath);
    } catch (error) {
      if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
        break;
      }
      throw error;
    }
    if (!stats.isSymbolicLink()) {
      continue;
    }

    let resolvedLink: string;
    try {
      resolvedLink = await fs.realpath(currentPath);
    } catch {
      throw new Error(
        `File "${relativePath}" cannot be safely resolved within the project root.`,
      );
    }
    if (!isWithinDirectory(projectRoot, resolvedLink)) {
      throw new Error(
        `File "${relativePath}" resolves outside the project root through a symbolic link.`,
      );
    }
    currentPath = resolvedLink;
  }

  return destination;
}

function sourceCanUseJsx(source: string): boolean {
  try {
    parse(source, {
      sourceType: 'module',
      plugins: ['jsx'],
    });
    return true;
  } catch {
    return false;
  }
}

function getPulledJsPath(existingPath: string, source: string): string {
  const extension = path.extname(existingPath).toLowerCase();
  if (
    (extension === '.js' || extension === '.jsx') &&
    !sourceCanUseJsx(source)
  ) {
    return `${existingPath.slice(0, -extension.length)}.tsx`;
  }
  return existingPath;
}

export function buildSkippedLocalOnlyPullResources(
  localOnlyCount: number,
  deleteLocalOnly: boolean,
): CommandSummaryResource[] {
  if (localOnlyCount === 0 || deleteLocalOnly) {
    return [];
  }
  return [
    {
      label: 'Components',
      count: localOnlyCount,
      unit: 'local-only component delete',
      unitPlural: 'local-only component deletes',
      action: 'skipped',
    },
  ];
}

export function createComponentsPullTask(
  apiService: ApiService,
  componentDir: string,
  skipOverwrite: boolean,
): PullTask {
  let components: Record<string, Component> = {};
  const localComponentMap = new Map<string, DiscoveredComponent>();
  let localOnlyComponents: DiscoveredComponent[] = [];
  let preferJsxForNewComponents = false;

  function buildMetadata(component: Component): Metadata {
    const metadata: Metadata = {
      name: component.name,
      machineName: component.machineName,
      status: component.status,
      required: component.required || [],
      props: {
        properties: component.props || {},
      },
      slots: component.slots || {},
    };

    if (component.dataDependencies?.entityFields) {
      metadata.dataDependencies = {
        entityFields: component.dataDependencies.entityFields,
      };
    }

    return metadata;
  }

  function writeComponentFiles(
    component: Component,
    paths: { metadataPath: string; jsPath: string; cssPath: string },
  ): Promise<void[]> {
    const metadata = buildMetadata(component);
    const writes: Promise<void>[] = [
      fs.writeFile(paths.metadataPath, yaml.dump(metadata), 'utf-8'),
    ];

    if (component.sourceCodeJs) {
      writes.push(fs.writeFile(paths.jsPath, component.sourceCodeJs, 'utf-8'));
    }

    if (component.sourceCodeCss) {
      writes.push(
        fs.writeFile(paths.cssPath, component.sourceCodeCss, 'utf-8'),
      );
    }

    return Promise.all(writes);
  }

  return {
    startLabel: 'Pulling components',
    stopLabel: 'Pulled components',

    async prepare(): Promise<PullTaskPrepareResult> {
      const [fetchedComponents, discoveryResult] = await Promise.all([
        apiService.listComponents(),
        discoverCanvasProject({ componentRoot: componentDir }),
      ]);

      // External components are implemented by the configured external
      // application and synced into Drupal as metadata only: pulling them
      // would create phantom local components without an implementation.
      components = Object.fromEntries(
        Object.entries(fetchedComponents).filter(
          ([, component]) => component.type !== 'external',
        ),
      );

      for (const discovered of discoveryResult.components) {
        localComponentMap.set(discovered.name, discovered);
      }
      preferJsxForNewComponents =
        discoveryResult.components.length > 0 &&
        discoveryResult.components.every((component) => {
          if (!component.jsEntryPath) {
            return false;
          }
          const extension = path.extname(component.jsEntryPath).toLowerCase();
          return extension === '.js' || extension === '.jsx';
        });

      const remoteMachineNames = new Set(
        Object.values(components).map((c) => c.machineName),
      );
      localOnlyComponents = discoveryResult.components.filter(
        (d) => !remoteMachineNames.has(d.name),
      );

      const total = Object.keys(components).length;
      const lines: string[] = [];

      if (total > 0) {
        const existingCount = Object.values(components).filter((component) =>
          localComponentMap.has(component.machineName),
        ).length;
        const newCount = total - existingCount;
        lines.push(
          formatSummaryLine('Components', total, newCount, existingCount),
        );
      }

      if (localOnlyComponents.length > 0) {
        const n = localOnlyComponents.length;
        lines.push(`Components: ${n} delete (local-only)`);
      }

      return {
        summaryLines: lines,
        localOnlyCount: localOnlyComponents.length,
      };
    },

    async execute(options?: {
      deleteLocalOnly?: boolean;
    }): Promise<PullTaskResult> {
      const results: Result[] = [];

      for (const component of Object.values(components)) {
        try {
          const discovered = localComponentMap.get(component.machineName);

          if (discovered) {
            if (skipOverwrite) {
              results.push({
                itemName: component.machineName,
                success: true,
                details: [{ content: 'Skipped (already exists)' }],
              });
              continue;
            }

            const dir = path.dirname(discovered.metadataPath);
            const existingJsPath = discovered.jsEntryPath;
            const defaultJsPath = existingJsPath ?? path.join(dir, 'index.tsx');
            const pulledJsPath = component.sourceCodeJs
              ? getPulledJsPath(defaultJsPath, component.sourceCodeJs)
              : defaultJsPath;
            await writeComponentFiles(component, {
              metadataPath: discovered.metadataPath,
              jsPath: pulledJsPath,
              cssPath: discovered.cssEntryPath ?? path.join(dir, 'index.css'),
            });
            if (existingJsPath && pulledJsPath !== existingJsPath) {
              await fs.rm(existingJsPath);
            }
          } else {
            const dir = path.join(componentDir, component.machineName);
            await fs.mkdir(dir, { recursive: true });
            const defaultExtension =
              preferJsxForNewComponents &&
              component.sourceCodeJs &&
              sourceCanUseJsx(component.sourceCodeJs)
                ? '.jsx'
                : '.tsx';
            const defaultJsPath = path.join(dir, `index${defaultExtension}`);
            const pulledJsPath = component.sourceCodeJs
              ? getPulledJsPath(defaultJsPath, component.sourceCodeJs)
              : defaultJsPath;
            await writeComponentFiles(component, {
              metadataPath: path.join(dir, 'component.yml'),
              jsPath: pulledJsPath,
              cssPath: path.join(dir, 'index.css'),
            });
          }

          results.push({
            itemName: component.machineName,
            success: true,
          });
        } catch (error) {
          results.push({
            itemName: component.machineName,
            success: false,
            details: [
              {
                content: error instanceof Error ? error.message : String(error),
              },
            ],
          });
        }
      }

      if (options?.deleteLocalOnly && localOnlyComponents.length > 0) {
        for (const discovered of localOnlyComponents) {
          try {
            await fs.rm(discovered.directory, { recursive: true, force: true });
            results.push({
              itemName: discovered.name,
              success: true,
              details: [{ content: 'Deleted' }],
            });
          } catch (error) {
            results.push({
              itemName: discovered.name,
              success: false,
              details: [
                {
                  content:
                    error instanceof Error ? error.message : String(error),
                },
              ],
            });
          }
        }
      }

      return { results, title: 'Pulled components', label: 'Component' };
    },
  };
}

export function createPagesPullTask(
  apiService: ApiService,
  pagesDir: string,
  skipOverwrite: boolean,
): PullTask {
  let pages: Record<string, PageListItem> = {};
  const localPageMap = new Map<string, DiscoveredPage>();
  const localPageSlugMap = new Map<string, DiscoveredPage>();

  function getPageSlug(page: Pick<PageListItem, 'path' | 'uuid'>): string {
    const slug = page.path.replace(/^\/+|\/+$/g, '').replace(/\//g, '-');

    if (slug) {
      return slug;
    }

    return page.path === '/' ? 'index' : page.uuid;
  }

  function getDiscoveredPage(page: PageListItem): DiscoveredPage | undefined {
    const byUuid = localPageMap.get(page.uuid);
    if (byUuid) {
      return byUuid;
    }

    return localPageSlugMap.get(getPageSlug(page));
  }

  return {
    startLabel: 'Pulling pages',
    stopLabel: 'Pulled pages',

    async prepare(): Promise<PullTaskPrepareResult> {
      const [fetchedPages, discoveryResult] = await Promise.all([
        apiService.listPages(),
        discoverCanvasProject({ pagesRoot: pagesDir }),
      ]);

      pages = fetchedPages;

      for (const discovered of discoveryResult.pages) {
        if (discovered.uuid) {
          localPageMap.set(discovered.uuid, discovered);
        }
        localPageSlugMap.set(discovered.slug, discovered);
      }

      const total = Object.keys(pages).length;
      if (total === 0) return { summaryLines: [], localOnlyCount: 0 };

      const existingCount = Object.values(pages).filter((page) =>
        Boolean(getDiscoveredPage(page)),
      ).length;
      const newCount = total - existingCount;

      const lines = [
        formatSummaryLine('Pages', total, newCount, existingCount),
      ];
      return { summaryLines: lines, localOnlyCount: 0 };
    },

    async execute(): Promise<PullTaskResult> {
      const results: Result[] = [];

      for (const page of Object.values(pages)) {
        try {
          const discovered = getDiscoveredPage(page);

          if (discovered && skipOverwrite) {
            results.push({
              itemName: page.title,
              success: true,
              details: [{ content: 'Skipped (already exists)' }],
            });
            continue;
          }

          const fullPage = await apiService.getPage(page.id);

          const nonJsComponents = fullPage.components.filter(
            (c) => !c.component_id.startsWith('js.'),
          );
          if (nonJsComponents.length > 0) {
            const unsupported = [
              ...new Set(nonJsComponents.map((c) => c.component_id)),
            ].join(', ');
            results.push({
              itemName: page.title,
              success: false,
              details: [
                {
                  content: `Skipped: contains unsupported components (${unsupported}). Only code components are supported.`,
                },
              ],
            });
            continue;
          }

          const localData = pageToAuthoredSpec(fullPage);

          const fileName = getPageSlug(page);
          const filePath =
            discovered?.path ?? path.join(pagesDir, `${fileName}.json`);
          await fs.mkdir(path.dirname(filePath), { recursive: true });
          await fs.writeFile(
            filePath,
            JSON.stringify(localData, null, 2) + '\n',
            'utf-8',
          );

          results.push({ itemName: page.title, success: true });
        } catch (error) {
          results.push({
            itemName: page.title,
            success: false,
            details: [
              {
                content: error instanceof Error ? error.message : String(error),
              },
            ],
          });
        }
      }

      return { results, title: 'Pulled pages', label: 'Page' };
    },
  };
}

export function createContentTemplatesPullTask(
  apiService: ApiService,
  contentTemplatesDir: string,
  skipOverwrite: boolean,
): PullTask {
  let templates: Record<string, ContentTemplateListItem> = {};
  const localById = new Map<string, DiscoveredContentTemplate>();

  return {
    startLabel: 'Pulling content templates',
    stopLabel: 'Pulled content templates',

    async prepare(): Promise<PullTaskPrepareResult> {
      const [fetchedTemplates, discoveryResult] = await Promise.all([
        apiService.listContentTemplates(),
        discoverCanvasProject({ contentTemplatesRoot: contentTemplatesDir }),
      ]);

      templates = fetchedTemplates;

      for (const discovered of discoveryResult.contentTemplates) {
        localById.set(discovered.slug, discovered);
      }

      const total = Object.keys(templates).length;
      if (total === 0) return { summaryLines: [], localOnlyCount: 0 };

      const existingCount = Object.values(templates).filter((template) =>
        localById.has(template.id),
      ).length;
      const newCount = total - existingCount;

      const lines = [
        formatSummaryLine('Content templates', total, newCount, existingCount),
      ];
      return { summaryLines: lines, localOnlyCount: 0 };
    },

    async execute(): Promise<PullTaskResult> {
      const results: Result[] = [];

      for (const listItem of Object.values(templates)) {
        try {
          const discovered = localById.get(listItem.id);

          if (discovered && skipOverwrite) {
            results.push({
              itemName: listItem.label,
              success: true,
              details: [{ content: 'Skipped (already exists)' }],
            });
            continue;
          }

          const fullTemplate = await apiService.getContentTemplate(listItem.id);

          const authored = contentTemplateToAuthored(fullTemplate);

          const filePath =
            discovered?.path ??
            path.join(contentTemplatesDir, `${listItem.id}.json`);
          const resolvedDir = path.resolve(contentTemplatesDir);
          if (!path.resolve(filePath).startsWith(resolvedDir + path.sep)) {
            throw new Error(
              `Content template ID "${listItem.id}" resolves outside the content templates directory.`,
            );
          }
          await fs.mkdir(path.dirname(filePath), { recursive: true });
          await fs.writeFile(
            filePath,
            JSON.stringify(authored, null, 2) + '\n',
            'utf-8',
          );

          results.push({
            itemName: listItem.label,
            success: true,
          });
        } catch (error) {
          results.push({
            itemName: listItem.label,
            success: false,
            details: [
              {
                content: error instanceof Error ? error.message : String(error),
              },
            ],
          });
        }
      }

      return {
        results,
        title: 'Pulled content templates',
        label: 'Content template',
      };
    },
  };
}

export function createPageTemplatesPullTask(
  apiService: ApiService,
  pageTemplatesDir: string,
  skipOverwrite: boolean,
): PullTask {
  let pageVariants: Record<string, PageVariant> = {};
  let defaultVariantId: string | null = null;
  const localPageTemplateMap = new Map<string, DiscoveredPageTemplate>();

  return {
    startLabel: 'Pulling page templates',
    stopLabel: 'Pulled page templates',

    async prepare(): Promise<PullTaskPrepareResult> {
      const [fetched, defaultVariant, discoveryResult] = await Promise.all([
        apiService.listPageVariants(),
        apiService.getDefaultPageVariant(),
        discoverCanvasProject({ pageTemplatesRoot: pageTemplatesDir }),
      ]);

      pageVariants = fetched;
      defaultVariantId = defaultVariant.default_page_variant;
      for (const discovered of discoveryResult.pageTemplates) {
        localPageTemplateMap.set(discovered.id, discovered);
      }

      const total = Object.keys(pageVariants).length;
      if (total === 0) return { summaryLines: [], localOnlyCount: 0 };

      const existingCount = Object.values(pageVariants).filter((variant) =>
        localPageTemplateMap.has(variant.id),
      ).length;
      const newCount = total - existingCount;

      return {
        summaryLines: [
          formatSummaryLine('Page templates', total, newCount, existingCount),
        ],
        localOnlyCount: 0,
      };
    },

    async execute(): Promise<PullTaskResult> {
      const results: Result[] = [];

      for (const variant of Object.values(pageVariants)) {
        try {
          const discovered = localPageTemplateMap.get(variant.id);
          if (discovered && skipOverwrite) {
            results.push({
              itemName: variant.id,
              success: true,
              details: [{ content: 'Skipped (already exists)' }],
            });
            continue;
          }

          // The intrinsic "Page content" marker is part of every variant and
          // round-trips like any other component; everything else must be a
          // code component for the authored codebase to build it.
          const unsupportedComponents = variant.component_tree.filter(
            (c) =>
              !c.component_id.startsWith('js.') &&
              !c.component_id.startsWith('marker.'),
          );
          if (unsupportedComponents.length > 0) {
            const unsupported = [
              ...new Set(unsupportedComponents.map((c) => c.component_id)),
            ].join(', ');
            results.push({
              itemName: variant.id,
              success: false,
              details: [
                {
                  content: `Skipped: contains unsupported components (${unsupported}). Only code components and the page content marker are supported.`,
                },
              ],
            });
            continue;
          }

          const localData = pageVariantToAuthoredSpec(
            variant,
            variant.id === defaultVariantId,
          );
          const filePath =
            discovered?.path ??
            path.join(pageTemplatesDir, `${variant.id}.json`);
          await fs.mkdir(path.dirname(filePath), { recursive: true });
          await fs.writeFile(
            filePath,
            JSON.stringify(localData, null, 2) + '\n',
            'utf-8',
          );

          results.push({ itemName: variant.id, success: true });
        } catch (error) {
          results.push({
            itemName: variant.id,
            success: false,
            details: [
              {
                content: error instanceof Error ? error.message : String(error),
              },
            ],
          });
        }
      }

      return {
        results,
        title: 'Pulled page templates',
        label: 'Page template',
      };
    },
  };
}

export function createAssetsPullTask(
  apiService: ApiService,
  globalCssPath: string,
  skipOverwrite: boolean,
  projectRoot: string,
): PullTask {
  let globalCss = '';
  let localExists = false;
  let siteImportMap: ImportMap | undefined;
  let packageJson: string | null = null;
  let packageJsonExists = false;
  const packageJsonPath = path.join(projectRoot, 'package.json');
  let codebaseAssets: AssetLibraryManifestEntry[] = [];
  let bundledSources: AssetLibraryBundledSource[] = [];

  return {
    startLabel: 'Pulling assets',
    stopLabel: 'Pulled assets',

    async prepare(): Promise<PullTaskPrepareResult> {
      const globalAssetLibrary = await apiService.getGlobalAssetLibrary();
      siteImportMap = globalAssetLibrary?.importMap;
      globalCss = globalAssetLibrary?.css?.original || '';
      packageJson = globalAssetLibrary?.packageJson || null;
      codebaseAssets = (globalAssetLibrary?.assets ?? []).filter(
        (entry): entry is AssetLibraryManifestEntry =>
          typeof entry.path === 'string' && entry.path.length > 0,
      );
      // Sources of local modules bundled into other artifacts. They have no
      // `uri` and are absent from the runtime import map; a pull restores them
      // verbatim so the editable file reappears on disk.
      bundledSources = (globalAssetLibrary?.bundledSources ?? []).filter(
        (entry): entry is AssetLibraryBundledSource =>
          typeof entry.path === 'string' &&
          entry.path.length > 0 &&
          typeof entry.source === 'string',
      );
      // Collect the asset sub-items that are present into one compact line,
      // e.g. `Assets: global CSS, package.json, 7 local imports pull`.
      const assetParts: string[] = [];
      if (globalCss) {
        localExists = await fs
          .access(globalCssPath)
          .then(() => true)
          .catch(() => false);
        assetParts.push('global CSS');
      }
      if (packageJson) {
        packageJsonExists = await fs
          .access(packageJsonPath)
          .then(() => true)
          .catch(() => false);
        assetParts.push('package.json');
      }
      const localCount = codebaseAssets.length + bundledSources.length;
      if (localCount > 0) {
        assetParts.push(
          `${localCount} local ${pluralizeLabel(localCount, 'import')}`,
        );
      }
      if (siteImportMap) {
        assetParts.push(SITE_IMPORT_MAP_FILE);
      }
      const summaryLines: string[] =
        assetParts.length > 0 ? [`Assets: ${assetParts.join(', ')} pull`] : [];
      return { summaryLines, localOnlyCount: 0 };
    },

    async execute(): Promise<PullTaskResult> {
      const results: Result[] = [];
      // Record which bare specifiers this site resolves, so builds can tell a
      // module-contributed import from a typo without reaching the site.
      if (siteImportMap) {
        try {
          await writeSiteImportMap(projectRoot, siteImportMap);
          results.push({ itemName: SITE_IMPORT_MAP_FILE, success: true });
        } catch (error) {
          results.push({
            itemName: SITE_IMPORT_MAP_FILE,
            success: false,
            details: [
              {
                content: error instanceof Error ? error.message : String(error),
              },
            ],
          });
        }
      }
      const notes: string[] = [];
      // Set when the pulled `package.json` is newly created or its content
      // differs from what was on disk, so the user is reminded to reinstall
      // dependencies. An identical overwrite raises no note.
      let packageJsonChanged = false;
      if (globalCss) {
        try {
          if (skipOverwrite && localExists) {
            results.push({
              itemName: 'global.css',
              success: true,
              details: [{ content: 'Skipped (already exists)' }],
            });
          } else {
            await fs.mkdir(path.dirname(globalCssPath), { recursive: true });
            const outputCss = ensureTailwindImportAtTop(globalCss);
            await fs.writeFile(globalCssPath, outputCss, 'utf-8');
            results.push({ itemName: 'global.css', success: true });
          }
        } catch (error) {
          const errorMessage =
            error instanceof Error ? error.message : String(error);
          results.push({
            itemName: 'global.css',
            success: false,
            details: [{ content: errorMessage }],
          });
        }
      }
      if (packageJson) {
        try {
          if (skipOverwrite && packageJsonExists) {
            results.push({
              itemName: 'package.json',
              success: true,
              details: [{ content: 'Skipped (already exists)' }],
            });
          } else {
            // Compare against the on-disk file (if any) before overwriting, so
            // the dependency-install reminder only fires on a real change.
            const previous = packageJsonExists
              ? await fs.readFile(packageJsonPath, 'utf-8').catch(() => null)
              : null;
            packageJsonChanged = previous !== packageJson;
            await fs.writeFile(packageJsonPath, packageJson, 'utf-8');
            results.push({ itemName: 'package.json', success: true });
          }
        } catch (error) {
          const errorMessage =
            error instanceof Error ? error.message : String(error);
          results.push({
            itemName: 'package.json',
            success: false,
            details: [{ content: errorMessage }],
          });
        }
      }
      const resolvedProjectRoot = await fs.realpath(projectRoot);
      for (const entry of codebaseAssets) {
        // `entry.path` is guaranteed a non-empty string by the prepare() filter.
        const relativePath = entry.path as string;
        try {
          const dest = await resolveAssetPullDestination(
            resolvedProjectRoot,
            relativePath,
          );
          const destExists = await fs
            .access(dest)
            .then(() => true)
            .catch(() => false);
          if (skipOverwrite && destExists) {
            results.push({
              itemName: relativePath,
              success: true,
              details: [{ content: 'Skipped (already exists)' }],
            });
            continue;
          }
          await fs.mkdir(path.dirname(dest), { recursive: true });
          if (typeof entry.source === 'string') {
            // Text module: write the verbatim original source (the `uri`
            // artifact holds minified compiled JS, which is not editable).
            await fs.writeFile(dest, entry.source, 'utf-8');
          } else if (entry.url) {
            // Binary asset: the `uri` artifact holds the original bytes;
            // download over HTTP and write verbatim.
            const buffer = await apiService.downloadFile(entry.url);
            await fs.writeFile(dest, buffer);
          } else {
            throw new Error('No source or downloadable URL available.');
          }
          results.push({ itemName: relativePath, success: true });
        } catch (error) {
          const errorMessage =
            error instanceof Error ? error.message : String(error);
          results.push({
            itemName: relativePath,
            success: false,
            details: [{ content: errorMessage }],
          });
        }
      }
      for (const entry of bundledSources) {
        const relativePath = entry.path;
        try {
          const dest = await resolveAssetPullDestination(
            resolvedProjectRoot,
            relativePath,
          );
          const destExists = await fs
            .access(dest)
            .then(() => true)
            .catch(() => false);
          if (skipOverwrite && destExists) {
            results.push({
              itemName: relativePath,
              success: true,
              details: [{ content: 'Skipped (already exists)' }],
            });
            continue;
          }
          await fs.mkdir(path.dirname(dest), { recursive: true });
          // Bundled sources are always verbatim text; they never carry a `uri`
          // or downloadable URL because they are not standalone artifacts.
          await fs.writeFile(dest, entry.source, 'utf-8');
          results.push({ itemName: relativePath, success: true });
        } catch (error) {
          const errorMessage =
            error instanceof Error ? error.message : String(error);
          results.push({
            itemName: relativePath,
            success: false,
            details: [{ content: errorMessage }],
          });
        }
      }
      if (packageJsonChanged) {
        notes.push(
          'package.json changed. Run `npm install` to install dependencies.',
        );
      }
      return {
        results,
        title: 'Pulled assets',
        label: 'Asset',
        notes: notes.length > 0 ? notes : undefined,
      };
    },
  };
}

export function createFontsPullTask(
  apiService: ApiService,
  projectRoot: string,
): PullTask {
  let totalFontVariants = 0;
  let newCount = 0;
  let existingCount = 0;

  return {
    startLabel: 'Pulling brand kit',
    stopLabel: 'Pulled brand kit',

    async prepare(): Promise<PullTaskPrepareResult> {
      const [brandKit, brandKitConfig] = await Promise.all([
        apiService.getBrandKit(),
        readBrandKitConfig(projectRoot),
      ]);

      const remoteFonts = brandKit.fonts ?? [];
      totalFontVariants = remoteFonts.length;
      if (totalFontVariants === 0) {
        return { summaryLines: [], localOnlyCount: 0 };
      }

      const existingKeys = buildExistingVariantKeys(
        brandKitConfig?.families ?? [],
      );

      existingCount = remoteFonts.filter((e) =>
        existingKeys.has(
          variantKey(e.family, e.weight ?? '400', e.style ?? 'normal'),
        ),
      ).length;
      newCount = remoteFonts.filter(
        (e) =>
          e.url &&
          !existingKeys.has(
            variantKey(e.family, e.weight ?? '400', e.style ?? 'normal'),
          ),
      ).length;

      const fontVariantSummary = formatSummaryLine(
        'brand kit',
        totalFontVariants,
        newCount,
        existingCount,
        'font variant',
      );
      return {
        summaryLines: [fontVariantSummary],
        localOnlyCount: 0,
      };
    },

    async execute(): Promise<PullTaskResult> {
      const config = getConfig();
      const result = await pullFonts(apiService, projectRoot, config.fonts);

      const results: Result[] = [];

      for (const entry of result.downloaded) {
        results.push({
          itemName: `${entry.name} ${entry.weights?.[0] ?? '400'} ${entry.styles?.[0] ?? 'normal'}`,
          success: true,
        });
      }

      if (result.skipped > 0) {
        results.push({
          itemName: 'font variants',
          success: true,
          details: [
            {
              content: `Skipped ${result.skipped} (already in config)`,
            },
          ],
        });
      }

      if (result.count > 0) {
        await updateBrandKitConfig(projectRoot, result.downloaded);
      }

      return {
        results,
        title: 'Pulled brand kit',
        label: 'Font variant',
      };
    },
  };
}

export function pullCommand(program: Command): void {
  program
    .command('pull')
    .description(
      'pull components, global CSS, and optional fonts and pages from Drupal',
    )
    .option('--client-id <id>', 'Client ID')
    .option('--client-secret <secret>', 'Client Secret')
    .option('--site-url <url>', 'Site URL')
    .option('--scope <scope>', 'Scope')
    .addOption(
      new Option(
        '--include-pages [enabled]',
        'Include pages in the pull operation',
      )
        .preset('true')
        .argParser(parseBooleanOption)
        .default(undefined),
    )
    .addOption(
      new Option(
        '--include-content-templates [enabled]',
        'Include content templates in the pull operation',
      )
        .preset('true')
        .argParser(parseBooleanOption)
        .default(undefined),
    )
    .option('--no-pages', 'Exclude pages from the pull operation')
    .option(
      '--no-content-templates',
      'Exclude content templates from the pull operation',
    )
    .option(
      '--no-page-templates',
      'Exclude page templates from the pull operation',
    )
    .addOption(
      new Option(
        '--include-brand-kit [enabled]',
        'Include brand kit (fonts) in the pull operation',
      )
        .preset('true')
        .argParser(parseBooleanOption)
        .default(undefined),
    )
    .option('-d, --dir <directory>', 'Component directory')
    .option('-y, --yes', 'Skip all confirmation prompts')
    .option('--skip-overwrite', 'Skip pulling items that already exist locally')
    .action(async (options: PullOptions) => {
      printCommandIntro('pull');
      const s = p.spinner();
      let spinnerActive = false;
      let spinnerFailureLabel = 'Pull failed';

      try {
        applySyncOptionAliasesAndWarnings(options);
        updateConfigFromOptions(options);

        await ensureAuthConfig();
        await ensureConfig(['componentDir']);

        const config = getConfig();
        const apiService = await createApiService();
        const includesPages = config.includePages;
        const includesContentTemplates = config.includeContentTemplates;
        const includesPageTemplates = config.includePageTemplates;
        const includesBrandKit = config.includeBrandKit;

        // Build pull tasks.
        const projectRoot = process.cwd();
        const tasks: PullTask[] = [
          createComponentsPullTask(
            apiService,
            config.componentDir,
            options.skipOverwrite ?? false,
          ),
          createAssetsPullTask(
            apiService,
            resolveHostGlobalCssPath(projectRoot),
            options.skipOverwrite ?? false,
            projectRoot,
          ),
        ];

        if (includesBrandKit) {
          tasks.push(createFontsPullTask(apiService, projectRoot));
        }

        if (includesPages) {
          tasks.push(
            createPagesPullTask(
              apiService,
              config.pagesDir,
              options.skipOverwrite ?? false,
            ),
          );
        }

        if (includesPageTemplates) {
          tasks.push(
            createPageTemplatesPullTask(
              apiService,
              config.pageTemplatesDir,
              options.skipOverwrite ?? false,
            ),
          );
        }

        if (includesContentTemplates) {
          tasks.push(
            createContentTemplatesPullTask(
              apiService,
              path.resolve(projectRoot, config.contentTemplatesDir),
              options.skipOverwrite ?? false,
            ),
          );
        }

        // Fetch remote data and discover local state.
        s.start('Fetching remote data');
        spinnerActive = true;
        spinnerFailureLabel = 'Fetch failed';
        const prepareResults = await Promise.all(tasks.map((t) => t.prepare()));
        const summaryLines = prepareResults.flatMap((r) => r.summaryLines);
        const localOnlyCount = prepareResults.reduce(
          (sum, r) => sum + r.localOnlyCount,
          0,
        );
        if (summaryLines.length === 0) {
          s.stop('Nothing to pull', 0);
          p.outro('Nothing to pull');
          return;
        }

        s.stop('Fetched remote data', 0);
        spinnerActive = false;
        p.log.message(formatPullPlan(summaryLines));

        if (!options.yes) {
          const confirmed = await p.confirm({
            message: `Pull from ${config.siteUrl}?`,
            initialValue: true,
          });
          if (p.isCancel(confirmed) || !confirmed) {
            p.cancel('Operation cancelled');
            return;
          }
        }

        let deleteLocalOnly = false;
        if (localOnlyCount > 0) {
          if (options.yes) {
            deleteLocalOnly = true;
          } else {
            const deleteLocal = await p.confirm({
              message: `Delete ${localOnlyCount} local ${pluralizeComponent(localOnlyCount)} that no longer exist remotely?`,
              initialValue: false,
            });
            if (p.isCancel(deleteLocal)) {
              p.cancel('Operation cancelled');
              return;
            }
            deleteLocalOnly = Boolean(deleteLocal);
          }
        }
        const skippedResources = buildSkippedLocalOnlyPullResources(
          localOnlyCount,
          deleteLocalOnly,
        );

        const plannedTasks = tasks.filter(
          (_task, index) => prepareResults[index].summaryLines.length > 0,
        );
        const outcomes: PullTaskResult[] = [];

        for (const task of plannedTasks) {
          s.start(task.startLabel);
          spinnerActive = true;
          spinnerFailureLabel = task.stopLabel;

          const outcome = await task.execute({ deleteLocalOnly });
          outcomes.push(outcome);
          const taskHasFailures = outcome.results.some(
            (result) => !result.success,
          );
          s.stop(task.stopLabel, taskHasFailures ? 2 : 0);
          spinnerActive = false;

          if (outcome.results.length === 0) {
            continue;
          }
          reportResults(outcome.results, outcome.title, outcome.label, {
            ...COMMAND_RESULT_REPORT_OPTIONS,
            showTitle: false,
          });
        }

        const hasFailures = outcomes.some((outcome) =>
          outcome.results.some((result) => !result.success),
        );

        if (skippedResources.length > 0) {
          const skippedLines: string[] = [];
          appendCommandSummarySection(
            skippedLines,
            'Skipped',
            skippedResources,
            'skipped',
          );
          p.log.message(skippedLines.join('\n'));
        }
        const notes = outcomes.flatMap((outcome) => outcome.notes ?? []);
        if (notes.length > 0) {
          p.note(notes.join('\n'));
        }
        p.outro(hasFailures ? 'Pull incomplete' : 'Pull completed');
        if (hasFailures) {
          process.exitCode = 1;
          return;
        }
      } catch (error) {
        if (spinnerActive) {
          s.stop(spinnerFailureLabel, 2);
        }
        const message = formatErrorMessage(error);
        reportResults(
          [
            {
              itemName: pullFailureItemName(message),
              success: false,
              details: [{ content: message }],
            },
          ],
          'Pull failed',
          'Item',
          COMMAND_RESULT_REPORT_OPTIONS,
        );
        p.outro('Pull failed');
        process.exitCode = 1;
      }
    });
}
