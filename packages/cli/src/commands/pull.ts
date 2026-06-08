import fs from 'fs/promises';
import path from 'path';
import chalk from 'chalk';
import { Option } from 'commander';
import yaml from 'js-yaml';
import * as p from '@clack/prompts';
import { discoverCanvasProject } from '@drupal-canvas/discovery';
import { resolveHostGlobalCssPath } from '@drupal-canvas/vite-compat';

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
import { contentTemplateToAuthored } from '../utils/content-templates';
import { ensureTailwindImportAtTop } from '../utils/ensure-global-css-tailwind-import';
import { pageToAuthoredSpec } from '../utils/pages';
import { regionToAuthoredSpec } from '../utils/regions';
import { reportResults } from '../utils/report-results';

import type {
  DiscoveredComponent,
  DiscoveredContentTemplate,
  DiscoveredPage,
  DiscoveredRegion,
} from '@drupal-canvas/discovery';
import type { Command } from 'commander';
import type { ApiService } from '../services/api';
import type { Component } from '../types/Component';
import type { ContentTemplateListItem } from '../types/ContentTemplate';
import type { Metadata } from '../types/Metadata';
import type { PageListItem } from '../types/Page';
import type { RegionListItem } from '../types/Region';
import type { Result } from '../types/Result';

interface PullOptions {
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  scope?: string;
  includePages?: boolean;
  includeContentTemplates?: boolean;
  includeRegions?: boolean;
  pages?: boolean;
  contentTemplates?: boolean;
  regions?: boolean;
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
  prepare(): Promise<PullTaskPrepareResult>;
  execute(options?: { deleteLocalOnly?: boolean }): Promise<PullTaskResult>;
}

export interface PullTaskResult {
  results: Result[];
  title: string;
  label: string;
}

function formatSummaryLine(
  label: string,
  total: number,
  newCount: number,
  existingCount: number,
): string {
  const plural = total === 1 ? label : `${label}s`;
  const details: string[] = [];
  if (newCount > 0) details.push(`${newCount} new`);
  if (existingCount > 0) details.push(`${existingCount} existing`);
  const suffix = details.length > 0 ? ` (${details.join(', ')})` : '';
  return `- ${total} ${plural}${suffix}`;
}

export function createComponentsPullTask(
  apiService: ApiService,
  componentDir: string,
  skipOverwrite: boolean,
): PullTask {
  let components: Record<string, Component> = {};
  const localComponentMap = new Map<string, DiscoveredComponent>();
  let localOnlyComponents: DiscoveredComponent[] = [];

  function buildMetadata(component: Component): Metadata {
    return {
      name: component.name,
      machineName: component.machineName,
      status: component.status,
      required: component.required || [],
      props: {
        properties: component.props || {},
      },
      slots: component.slots || {},
    };
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
    async prepare(): Promise<PullTaskPrepareResult> {
      const [fetchedComponents, discoveryResult] = await Promise.all([
        apiService.listComponents(),
        discoverCanvasProject({ componentRoot: componentDir }),
      ]);

      components = fetchedComponents;

      for (const discovered of discoveryResult.components) {
        localComponentMap.set(discovered.name, discovered);
      }

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
          formatSummaryLine('component', total, newCount, existingCount),
        );
      }

      if (localOnlyComponents.length > 0) {
        const n = localOnlyComponents.length;
        lines.push(`- ${n} ${pluralizeComponent(n)} to delete (local-only)`);
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
            await writeComponentFiles(component, {
              metadataPath: discovered.metadataPath,
              jsPath: discovered.jsEntryPath ?? path.join(dir, 'index.tsx'),
              cssPath: discovered.cssEntryPath ?? path.join(dir, 'index.css'),
            });
          } else {
            const dir = path.join(componentDir, component.machineName);
            await fs.mkdir(dir, { recursive: true });
            await writeComponentFiles(component, {
              metadataPath: path.join(dir, 'component.yml'),
              jsPath: path.join(dir, 'index.tsx'),
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

      const lines = [formatSummaryLine('page', total, newCount, existingCount)];
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
        formatSummaryLine('content template', total, newCount, existingCount),
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

export function createRegionsPullTask(
  apiService: ApiService,
  regionsDir: string,
  skipOverwrite: boolean,
): PullTask {
  let regions: Record<string, RegionListItem> = {};
  const localRegionMap = new Map<string, DiscoveredRegion>();

  return {
    async prepare(): Promise<PullTaskPrepareResult> {
      const [fetched, discoveryResult] = await Promise.all([
        apiService.listRegions(),
        discoverCanvasProject({ regionsRoot: regionsDir }),
      ]);

      regions = fetched;
      for (const discovered of discoveryResult.regions) {
        localRegionMap.set(discovered.region, discovered);
      }

      const total = Object.keys(regions).length;
      if (total === 0) return { summaryLines: [], localOnlyCount: 0 };

      const existingCount = Object.values(regions).filter((r) =>
        localRegionMap.has(r.region),
      ).length;
      const newCount = total - existingCount;

      return {
        summaryLines: [
          formatSummaryLine('global region', total, newCount, existingCount),
        ],
        localOnlyCount: 0,
      };
    },

    async execute(): Promise<PullTaskResult> {
      const results: Result[] = [];

      for (const listItem of Object.values(regions)) {
        try {
          const discovered = localRegionMap.get(listItem.region);
          if (discovered && skipOverwrite) {
            results.push({
              itemName: listItem.region,
              success: true,
              details: [{ content: 'Skipped (already exists)' }],
            });
            continue;
          }

          const region = await apiService.getRegion(listItem.id);

          const nonJsComponents = region.component_tree.filter(
            (c) => !c.component_id.startsWith('js.'),
          );
          if (nonJsComponents.length > 0) {
            const unsupported = [
              ...new Set(nonJsComponents.map((c) => c.component_id)),
            ].join(', ');
            results.push({
              itemName: region.region,
              success: false,
              details: [
                {
                  content: `Skipped: contains unsupported components (${unsupported}). Only code components are supported.`,
                },
              ],
            });
            continue;
          }

          const localData = regionToAuthoredSpec(region);
          const filePath =
            discovered?.path ?? path.join(regionsDir, `${region.region}.json`);
          await fs.mkdir(path.dirname(filePath), { recursive: true });
          await fs.writeFile(
            filePath,
            JSON.stringify(localData, null, 2) + '\n',
            'utf-8',
          );

          results.push({ itemName: region.region, success: true });
        } catch (error) {
          results.push({
            itemName: listItem.region,
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
        title: 'Pulled global regions',
        label: 'Global region',
      };
    },
  };
}

export function createAssetsPullTask(
  apiService: ApiService,
  globalCssPath: string,
  skipOverwrite: boolean,
): PullTask {
  let globalCss = '';
  let localExists = false;

  return {
    async prepare(): Promise<PullTaskPrepareResult> {
      const globalAssetLibrary = await apiService.getGlobalAssetLibrary();
      globalCss = globalAssetLibrary?.css?.original || '';
      if (!globalCss) {
        return { summaryLines: [], localOnlyCount: 0 };
      }
      localExists = await fs
        .access(globalCssPath)
        .then(() => true)
        .catch(() => false);
      return { summaryLines: ['- global CSS'], localOnlyCount: 0 };
    },

    async execute(): Promise<PullTaskResult> {
      const results: Result[] = [];
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
      return { results, title: 'Pulled assets', label: 'Asset' };
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

      return {
        summaryLines: [
          formatSummaryLine(
            'font variant',
            totalFontVariants,
            newCount,
            existingCount,
          ),
        ],
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
        title: 'Pulled fonts',
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
    .addOption(
      new Option(
        '--include-regions [enabled]',
        'Include global regions in the pull operation',
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
    .option('--no-regions', 'Exclude global regions from the pull operation')
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
      p.intro(chalk.bold('Drupal Canvas CLI: pull'));

      try {
        applySyncOptionAliasesAndWarnings(options);
        updateConfigFromOptions(options);

        await ensureAuthConfig();
        await ensureConfig(['componentDir']);

        const config = getConfig();
        const apiService = await createApiService();
        const includesPages = config.includePages;
        const includesContentTemplates = config.includeContentTemplates;
        const includesRegions = config.includeRegions;
        const includesBrandKit = config.includeBrandKit;

        const s = p.spinner();
        const contentParts: string[] = ['components', 'global CSS'];
        if (includesBrandKit) contentParts.push('fonts');
        if (includesPages) contentParts.push('pages');
        if (includesContentTemplates) contentParts.push('content templates');
        if (includesRegions) contentParts.push('global regions');
        const contentLabel = contentParts.join(', ');

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

        if (includesRegions) {
          tasks.push(
            createRegionsPullTask(
              apiService,
              config.regionsDir,
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
        s.start(`Fetching ${contentLabel}`);
        const prepareResults = await Promise.all(tasks.map((t) => t.prepare()));
        const summaryLines = prepareResults.flatMap((r) => r.summaryLines);
        const localOnlyCount = prepareResults.reduce(
          (sum, r) => sum + r.localOnlyCount,
          0,
        );
        if (summaryLines.length === 0) {
          s.stop('Nothing to pull');
          process.exit(0);
        }

        s.stop('Ready to pull:');
        p.log.message(summaryLines.join('\n'));

        if (!options.yes) {
          const confirmed = await p.confirm({
            message: `Pull from ${config.siteUrl}?`,
            initialValue: true,
          });
          if (p.isCancel(confirmed) || !confirmed) {
            p.cancel('Operation cancelled');
            process.exit(0);
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
              process.exit(0);
            }
            deleteLocalOnly = Boolean(deleteLocal);
          }
        }

        // Execute all tasks in parallel.
        s.start('Pulling');
        const outcomes = await Promise.all(
          tasks.map((t) => t.execute({ deleteLocalOnly })),
        );
        s.stop(chalk.green('Done'));

        // Report results.
        for (const outcome of outcomes) {
          if (outcome.results.length > 0) {
            reportResults(outcome.results, outcome.title, outcome.label);
          }
        }

        p.outro('⬇️ Pull completed successfully');
      } catch (error) {
        if (error instanceof Error) {
          p.note(chalk.red(`Error: ${error.message}`));
        } else {
          p.note(chalk.red(`Unknown error: ${String(error)}`));
        }
        process.exit(1);
      }
    });
}
