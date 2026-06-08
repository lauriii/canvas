import fs from 'fs/promises';
import path from 'path';
import chalk from 'chalk';
import { Option } from 'commander';
import * as p from '@clack/prompts';
import { discoverCanvasProject } from '@drupal-canvas/discovery';

import { ensureConfig, getConfig, parseBooleanSetting } from '../config.js';
import {
  buildFontPushPlannedResults,
  pushFonts,
} from '../lib/fonts/font-push.js';
import { createApiService, ensureAuthConfig } from '../services/api.js';
import { buildCanvasProject } from '../utils/build-project';
import {
  applySyncOptionAliasesAndWarnings,
  parseBooleanOption,
  pluralize,
  pluralizeComponent,
  updateConfigFromOptions,
} from '../utils/command-helpers';
import {
  collectContentTemplateResults,
  prepareContentTemplates,
  pushContentTemplates,
} from '../utils/prepare-content-templates-push';
import {
  collectPageResults,
  preparePages,
  pushPages,
} from '../utils/prepare-pages-push';
import {
  pushBuiltComponents,
  uploadGlobalAssetLibrary,
} from '../utils/prepare-push';
import {
  collectRegionResults,
  prepareRegions,
  pushRegions,
} from '../utils/prepare-regions-push';
import { reportResults } from '../utils/report-results';
import { createProgressCallback, processInPool } from '../utils/request-pool';
import { validateContentTemplates } from '../utils/validate-content-template';
import { validatePages } from '../utils/validate-page';
import { validateRegions } from '../utils/validate-region';

import type { Command } from 'commander';
import type { ApiService } from '../services/api.js';
import type {
  BrandKitFontEntry,
  BuildManifest,
  UploadedArtifact,
  UploadedArtifactResult,
} from '../types/Component.js';
import type { ContentTemplateListItem } from '../types/ContentTemplate.js';
import type { PageListItem } from '../types/Page.js';
import type { Result } from '../types/Result.js';

interface PushOptions {
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
}

export type SyncExclusionSource = 'flag' | 'deprecated-flag' | 'env' | 'config';

export interface SyncExclusionMessageOptions {
  noFlag: string;
  includeFlag: string;
  envName: string;
  configPath: string;
}

export function getSyncExclusionSource(
  noOption: boolean | undefined,
  includeOption: boolean | undefined,
  envValue: string | undefined,
): SyncExclusionSource {
  if (noOption === false) {
    return 'flag';
  }
  if (includeOption === false) {
    return 'deprecated-flag';
  }
  if (parseBooleanSetting(envValue ?? '') === false) {
    return 'env';
  }
  return 'config';
}

export function getSyncExclusionMessage(
  label: string,
  source: SyncExclusionSource,
  options: SyncExclusionMessageOptions,
): string {
  switch (source) {
    case 'flag':
      return `Local ${label} were found but excluded by ${options.noFlag}. Remove that flag to push them.`;
    case 'deprecated-flag':
      return `Local ${label} were found but excluded by deprecated ${options.includeFlag}=false. Remove that flag, or use ${options.noFlag} when you want to exclude them.`;
    case 'env':
      return `Local ${label} were found but excluded by deprecated ${options.envName}=false. Remove that environment variable, or set "${options.configPath}" to true in canvas.config.json to push them.`;
    case 'config':
      return `Local ${label} were found but excluded by "${options.configPath}": false in canvas.config.json. Set it to true to push them.`;
  }
}

/**
 * Reads the build manifest from the dist directory.
 */
export async function readBuildManifest(
  distDir: string,
): Promise<BuildManifest> {
  const manifestPath = path.join(distDir, 'canvas-manifest.json');
  const content = await fs.readFile(manifestPath, 'utf-8');
  return JSON.parse(content) as BuildManifest;
}

/**
 * Collects vendor, local, and shared artifact files from the build manifest.
 *
 * Only vendor and local entries are uploaded as file artifacts.
 * Component build artifacts are handled by js_component config entities,
 * and global CSS/JS is handled by the asset_library entity.
 */
export function collectManifestArtifacts(manifest: BuildManifest): Array<{
  name: string;
  filePath: string;
  type: 'vendor' | 'local' | 'shared';
}> {
  const files: Array<{
    name: string;
    filePath: string;
    type: 'vendor' | 'local' | 'shared';
  }> = [];

  for (const [specifier, filePath] of Object.entries(manifest.vendor)) {
    files.push({ name: specifier, filePath, type: 'vendor' as const });
  }

  for (const [specifier, filePath] of Object.entries(manifest.local)) {
    files.push({ name: specifier, filePath, type: 'local' as const });
  }

  // Add shared chunks - use filePath as the name since they don't have import specifiers
  for (const filePath of manifest.shared ?? []) {
    files.push({ name: filePath, filePath, type: 'shared' as const });
  }

  return files;
}

/**
 * Uploads artifact files and builds manifest entries from the results.
 */
async function uploadAndBuildManifest(
  files: Array<{
    name: string;
    filePath: string;
    type: 'vendor' | 'local' | 'shared';
  }>,
  distDir: string,
  apiService: Pick<ApiService, 'uploadArtifact'>,
  spinner: { message: (msg?: string) => void },
): Promise<{
  vendor: UploadedArtifact[];
  local: UploadedArtifact[];
  shared: UploadedArtifact[];
}> {
  const uploadProgress = createProgressCallback(
    spinner,
    'Uploading artifacts',
    new Set(files.map((file) => file.filePath)).size,
  );

  const uniqueFiles = Array.from(
    new Map(files.map((file) => [file.filePath, file])).values(),
  );

  const results = await processInPool(uniqueFiles, async (file) => {
    const absolutePath = path.resolve(distDir, file.filePath);
    const fileBuffer = await fs.readFile(absolutePath);
    const filename = path.basename(file.filePath);

    const uploadResult: UploadedArtifactResult =
      await apiService.uploadArtifact(filename, fileBuffer);
    uploadProgress();

    return uploadResult;
  });

  const uploadedByFilePath = new Map<string, UploadedArtifactResult>();
  const errors: string[] = [];

  for (const result of results) {
    if (result.success && result.result) {
      uploadedByFilePath.set(uniqueFiles[result.index].filePath, result.result);
    } else {
      const fileName = uniqueFiles[result.index]?.name || 'unknown';
      errors.push(
        `Failed to upload ${fileName}: ${result.error?.message || 'Unknown error'}`,
      );
    }
  }

  const grouped: {
    vendor: UploadedArtifact[];
    local: UploadedArtifact[];
    shared: UploadedArtifact[];
  } = {
    vendor: [],
    local: [],
    shared: [],
  };

  if (errors.length === 0) {
    for (const file of files) {
      const uploadResult = uploadedByFilePath.get(file.filePath);
      if (uploadResult) {
        grouped[file.type].push({
          name: file.name,
          uri: uploadResult.uri,
        });
      }
    }
  }

  if (errors.length > 0) {
    throw new Error(`Some uploads failed:\n${errors.join('\n')}`);
  }

  return grouped;
}

/**
 * Uploads build artifacts from manifest and syncs the uploaded manifest.
 */
export async function syncManifestArtifacts(
  outputDir: string,
  options: {
    apiService: Pick<ApiService, 'uploadArtifact' | 'syncManifest'>;
    createSpinner?: () => {
      start: (msg?: string) => void;
      stop: (msg?: string) => void;
      message: (msg?: string) => void;
    };
    logInfo?: (msg: string) => void;
  },
): Promise<{
  artifactCount: number;
  groupedManifest: {
    vendor: UploadedArtifact[];
    local: UploadedArtifact[];
    shared: UploadedArtifact[];
  };
}> {
  const createSpinner = options.createSpinner ?? (() => p.spinner());
  const emptyManifest = { vendor: [], local: [], shared: [] };

  const artifactFiles: Array<{
    name: string;
    filePath: string;
    type: 'vendor' | 'local' | 'shared';
  }> = [];
  try {
    const manifest = await readBuildManifest(outputDir);
    artifactFiles.push(...collectManifestArtifacts(manifest));
  } catch {
    // Build manifest may not exist if build wasn't run.
    // This is not fatal — components and global CSS were already pushed.
    options.logInfo?.(
      'No build manifest found, skipping vendor/local artifact sync',
    );
  }

  if (artifactFiles.length === 0) {
    options.logInfo?.(
      'No manifest artifacts to upload, skipping manifest sync',
    );
    return { artifactCount: 0, groupedManifest: emptyManifest };
  }

  const artifactSpinner = createSpinner();
  artifactSpinner.start('Uploading vendor/local artifacts');

  const groupedManifest = await uploadAndBuildManifest(
    artifactFiles,
    outputDir,
    options.apiService,
    artifactSpinner,
  );
  const artifactCount =
    groupedManifest.vendor.length +
    groupedManifest.local.length +
    groupedManifest.shared.length;
  artifactSpinner.stop(chalk.green(`Uploaded ${artifactCount} artifacts`));

  const syncSpinner = createSpinner();
  syncSpinner.start('Syncing manifest');
  await options.apiService.syncManifest({
    vendor: groupedManifest.vendor,
    local: groupedManifest.local,
    shared: groupedManifest.shared,
  });
  syncSpinner.stop(chalk.green('Manifest synced'));

  return { artifactCount, groupedManifest };
}

/**
 * Registers the push command.
 *
 * Pushes local components, global CSS, and vendor/local build artifacts to Drupal.
 * 1. Component configs (via js_component entities)
 * 2. Global CSS/JS (via asset_library)
 * 3. Vendor/local build artifacts (uploaded as files, tracked in manifest)
 */
export function pushCommand(program: Command): void {
  program
    .command('push')
    .description(
      'build and push local components, global CSS, vendor/local artifacts, and optional fonts and pages to Drupal',
    )
    .option('--client-id <id>', 'Client ID')
    .option('--client-secret <secret>', 'Client Secret')
    .option('--site-url <url>', 'Site URL')
    .option('--scope <scope>', 'Scope')
    .addOption(
      new Option(
        '--include-pages [enabled]',
        'Include pages in the push operation',
      )
        .preset('true')
        .argParser(parseBooleanOption)
        .default(undefined),
    )
    .addOption(
      new Option(
        '--include-content-templates [enabled]',
        'Include content templates in the push operation',
      )
        .preset('true')
        .argParser(parseBooleanOption)
        .default(undefined),
    )
    .addOption(
      new Option(
        '--include-regions [enabled]',
        'Include global regions in the push operation',
      )
        .preset('true')
        .argParser(parseBooleanOption)
        .default(undefined),
    )
    .option('--no-pages', 'Exclude pages from the push operation')
    .option(
      '--no-content-templates',
      'Exclude content templates from the push operation',
    )
    .option('--no-regions', 'Exclude global regions from the push operation')
    .addOption(
      new Option(
        '--include-brand-kit [enabled]',
        'Include brand kit (fonts) in the push operation',
      )
        .preset('true')
        .argParser(parseBooleanOption)
        .default(undefined),
    )
    .option('-d, --dir <directory>', 'Component directory')
    .option('-y, --yes', 'Skip confirmation prompts')
    .action(async (options: PushOptions) => {
      let apiService: ApiService | undefined;
      try {
        p.intro(chalk.bold('Drupal Canvas CLI: push'));
        // Update config with CLI options.
        applySyncOptionAliasesAndWarnings(options);
        updateConfigFromOptions(options);

        await ensureAuthConfig();
        await ensureConfig(['componentDir']);
        const config = getConfig();
        const { componentDir, aliasBaseDir, outputDir } = config;
        const includesPages = config.includePages;
        const includesContentTemplates = config.includeContentTemplates;
        const includesRegions = config.includeRegions;
        const includesBrandKit = config.includeBrandKit;
        const hasBrandKitFontsConfig = config.fonts !== undefined;
        // Step 1. Discover all components, pages, content templates and regions.
        const discoveryResult = await discoverCanvasProject({
          componentRoot: componentDir,
          pagesRoot: config.pagesDir,
          contentTemplatesRoot: config.contentTemplatesDir,
          regionsRoot: config.regionsDir,
          projectRoot: process.cwd(),
        });
        const {
          components,
          pages: allDiscoveredPages,
          contentTemplates: allDiscoveredContentTemplates,
          regions: allDiscoveredRegions,
          warnings,
        } = discoveryResult;
        const discoveredPages = includesPages ? allDiscoveredPages : [];
        const hasIgnoredPages = !includesPages && allDiscoveredPages.length > 0;
        const discoveredContentTemplates = includesContentTemplates
          ? allDiscoveredContentTemplates
          : [];
        const hasIgnoredContentTemplates =
          !includesContentTemplates && allDiscoveredContentTemplates.length > 0;
        const discoveredRegions = includesRegions ? allDiscoveredRegions : [];
        const hasIgnoredRegions =
          !includesRegions && allDiscoveredRegions.length > 0;
        const logIgnoredLocalResources = () => {
          if (hasIgnoredPages) {
            p.log.info(
              getSyncExclusionMessage(
                'pages',
                getSyncExclusionSource(
                  options.pages,
                  options.includePages,
                  process.env.CANVAS_INCLUDE_PAGES,
                ),
                {
                  noFlag: '--no-pages',
                  includeFlag: '--include-pages',
                  envName: 'CANVAS_INCLUDE_PAGES',
                  configPath: 'sync.pages',
                },
              ),
            );
          }
          if (hasIgnoredContentTemplates) {
            p.log.info(
              getSyncExclusionMessage(
                'content templates',
                getSyncExclusionSource(
                  options.contentTemplates,
                  options.includeContentTemplates,
                  process.env.CANVAS_INCLUDE_CONTENT_TEMPLATES,
                ),
                {
                  noFlag: '--no-content-templates',
                  includeFlag: '--include-content-templates',
                  envName: 'CANVAS_INCLUDE_CONTENT_TEMPLATES',
                  configPath: 'sync.contentTemplates',
                },
              ),
            );
          }
          if (hasIgnoredRegions) {
            p.log.info(
              getSyncExclusionMessage(
                'global regions',
                getSyncExclusionSource(
                  options.regions,
                  options.includeRegions,
                  process.env.CANVAS_INCLUDE_REGIONS,
                ),
                {
                  noFlag: '--no-regions',
                  includeFlag: '--include-regions',
                  envName: 'CANVAS_INCLUDE_REGIONS',
                  configPath: 'sync.regions',
                },
              ),
            );
          }
        };

        if (
          components.length === 0 &&
          discoveredPages.length === 0 &&
          discoveredContentTemplates.length === 0 &&
          discoveredRegions.length === 0 &&
          !(includesBrandKit && hasBrandKitFontsConfig)
        ) {
          logIgnoredLocalResources();
          p.log.warn(
            'No components, pages, content templates, or global regions found for the enabled sync settings.',
          );
          p.outro('Push aborted (nothing to push)');
          return;
        }

        if (
          components.length === 0 &&
          discoveredPages.length === 0 &&
          discoveredContentTemplates.length === 0 &&
          discoveredRegions.length === 0 &&
          includesBrandKit &&
          hasBrandKitFontsConfig
        ) {
          p.log.info(
            'No components, pages, content templates or global regions found; syncing Brand Kit fonts from canvas.brand-kit.json.',
          );
        }

        if (components.length === 0) {
          p.log.info('No components found. Skipping component push.');
        }

        logIgnoredLocalResources();

        apiService = await createApiService();
        const existingComponents =
          components.length > 0 ? await apiService.listComponents() : {};
        const remoteNames = new Set(Object.keys(existingComponents));
        const localNames = new Set(components.map((c) => c.name));

        let remoteBrandKitFonts: BrandKitFontEntry[] = [];
        if (includesBrandKit && config.fonts !== undefined) {
          try {
            const brandKit = await apiService.getBrandKit();
            remoteBrandKitFonts = brandKit.fonts ?? [];
          } catch {
            remoteBrandKitFonts = [];
          }
        }

        // Fetch remote pages early for the planned operations summary.
        const remotePages =
          includesPages && discoveredPages.length > 0
            ? await apiService.listPages()
            : {};
        const remotePageByUuid = new Map<string, PageListItem>();
        for (const remotePage of Object.values(remotePages)) {
          remotePageByUuid.set(remotePage.uuid, remotePage);
        }

        // Fetch remote content templates early for the planned operations summary.
        const remoteContentTemplates =
          includesContentTemplates && discoveredContentTemplates.length > 0
            ? await apiService.listContentTemplates()
            : {};
        const remoteContentTemplateById = new Map<
          string,
          ContentTemplateListItem
        >();
        for (const remote of Object.values(remoteContentTemplates)) {
          remoteContentTemplateById.set(remote.id, remote);
        }

        // Fetch remote page regions early for the planned operations summary.
        const remoteRegions = includesRegions
          ? await apiService.listRegions()
          : {};
        // Map each remote region's name to its full `{theme}.{region}` id so
        // the push step can resolve the PATCH/DELETE target without needing
        // the theme in the local file.
        const remoteRegionIdsByName = new Map(
          Object.values(remoteRegions).map(
            (r) => [r.region, `${r.theme}.${r.region}`] as const,
          ),
        );
        const localRegionNames = new Set(
          discoveredRegions.map((r) => r.region),
        );
        // Remote regions absent locally are deleted on push.
        const remoteRegionNamesToDelete = Array.from(
          remoteRegionIdsByName.keys(),
        ).filter((name) => !localRegionNames.has(name));

        // Build a preview of planned operations.
        const operationLabels: Record<string, string> = {
          create: chalk.green('Create'),
          update: chalk.cyan('Update'),
          delete: chalk.red('Delete'),
        };
        const plannedResults: Result[] = [
          ...components.map((c) => ({
            itemName: c.name,
            itemType: 'Component',
            success: true,
            details: [
              {
                content: remoteNames.has(c.name)
                  ? operationLabels.update
                  : operationLabels.create,
              },
            ],
          })),
          ...[...remoteNames]
            .filter((name) => !localNames.has(name))
            .map((name) => ({
              itemName: name,
              itemType: 'Component',
              success: true,
              details: [{ content: operationLabels.delete }],
            })),
          ...discoveredPages.map((page) => ({
            itemName: page.name,
            itemType: 'Page',
            success: true,
            details: [
              {
                content:
                  page.uuid && remotePageByUuid.has(page.uuid)
                    ? operationLabels.update
                    : operationLabels.create,
              },
            ],
          })),
          ...discoveredContentTemplates.map((template) => {
            const hasFullId =
              template.entityTypeId && template.bundle && template.viewMode;
            const templateId = hasFullId
              ? `${template.entityTypeId}.${template.bundle}.${template.viewMode}`
              : null;
            return {
              itemName: template.label ?? template.name,
              itemType: 'Content template',
              success: true,
              details: [
                {
                  content:
                    templateId && remoteContentTemplateById.has(templateId)
                      ? operationLabels.update
                      : operationLabels.create,
                },
              ],
            };
          }),
          ...discoveredRegions.map((region) => ({
            itemName: region.region,
            itemType: 'Global region',
            success: true,
            details: [
              {
                content: remoteRegionIdsByName.has(region.region)
                  ? operationLabels.update
                  : operationLabels.create,
              },
            ],
          })),
          ...remoteRegionNamesToDelete.map((name) => ({
            itemName: name,
            itemType: 'Global region',
            success: true,
            details: [{ content: operationLabels.delete }],
          })),
          ...(includesBrandKit && config.fonts !== undefined
            ? buildFontPushPlannedResults(config.fonts, remoteBrandKitFonts, {
                create: operationLabels.create,
                update: operationLabels.update,
                delete: operationLabels.delete,
              })
            : []),
        ];
        if (plannedResults.length > 0) {
          reportResults(plannedResults, 'Planned operations', 'Item', {
            preview: true,
          });
        }

        for (const warning of warnings) {
          const location = warning.path ? chalk.dim(` (${warning.path})`) : '';
          p.log.warn(`${warning.message}${location}`);
        }

        if (!options.yes) {
          const parts: string[] = [];
          if (components.length > 0) {
            parts.push(
              `${components.length} ${pluralizeComponent(components.length)}`,
            );
          }
          if (discoveredPages.length > 0) {
            parts.push(
              `${discoveredPages.length} ${pluralize(discoveredPages.length, 'page')}`,
            );
          }
          if (discoveredRegions.length > 0) {
            parts.push(
              `${discoveredRegions.length} global ${pluralize(discoveredRegions.length, 'region')}`,
            );
          }
          if (includesBrandKit && hasBrandKitFontsConfig) {
            parts.push('Brand Kit fonts (canvas.brand-kit.json)');
          }
          const confirmed = await p.confirm({
            message: `Push these changes to ${config.siteUrl}?`,
            initialValue: true,
          });
          if (p.isCancel(confirmed) || !confirmed) {
            p.cancel('Operation cancelled');
            return;
          }
        }

        await apiService.signalPushStart();

        // Step 2: Build components, global CSS, and manifest artifacts.
        const s2 = p.spinner();
        s2.start('Building project');
        const canvasBuild = await buildCanvasProject({
          projectRoot: process.cwd(),
          componentDir,
          aliasBaseDir,
          outputDir,
          discoveryResult,
          cleanOutputDir: true,
          buildTailwind: true,
          requireJsEntries: true,
          useLocalGlobalCss: true,
        });
        s2.stop(chalk.green('Built project'));

        if (canvasBuild.componentResults.some((r) => !r.success)) {
          reportResults(
            canvasBuild.componentResults,
            'Built components',
            'Component',
          );
          throw new Error('Component build failed. Nothing was pushed.');
        }

        if (canvasBuild.tailwindResult) {
          reportResults([canvasBuild.tailwindResult], 'Built assets', 'Asset');
          if (!canvasBuild.tailwindResult.success) {
            throw new Error(
              'Tailwind build failed, global assets upload aborted. Nothing was pushed.',
            );
          }
        }

        if (canvasBuild.vendorImportCount > 0) {
          p.log.info(
            chalk.green(
              `Bundled ${canvasBuild.vendorImportCount} vendor ${pluralize(canvasBuild.vendorImportCount, 'package')} → ${outputDir}/vendor/`,
            ),
          );
        }
        if (canvasBuild.localImportCount > 0) {
          p.log.info(
            chalk.green(
              `Bundled ${canvasBuild.localImportCount} local ${pluralize(canvasBuild.localImportCount, 'import')} → ${outputDir}/local/`,
            ),
          );
        }

        let componentResults: Result[] = [];
        let includeGlobalCss = false;
        let fontCount = 0;

        // Build and push components
        if (components.length > 0) {
          componentResults = await pushBuiltComponents(
            canvasBuild.builtComponents,
            apiService,
            'Pushing',
          );
          if (componentResults.some((r) => !r.success)) {
            reportResults(componentResults, 'Pushed components', 'Component');
            throw new Error('Component push failed. Push aborted.');
          }
          reportResults(componentResults, 'Pushed components', 'Component');
        }

        // Upload Tailwind CSS.
        const globalCssResult = await uploadGlobalAssetLibrary(
          apiService,
          config.outputDir,
        );
        reportResults([globalCssResult], 'Pushed assets', 'Asset');
        if (!globalCssResult.success) {
          throw new Error('Push aborted (incomplete). Try again.');
        }
        includeGlobalCss = true;

        // Step 4b: Push fonts from canvas.brand-kit.json (when configured)
        if (includesBrandKit && config.fonts) {
          const fontOutcomeLabels: Record<string, string> = {
            create: chalk.green('Create'),
            update: chalk.cyan('Update'),
            delete: chalk.red('Delete'),
            unchanged: chalk.dim('Unchanged'),
          };
          const fontSpinner = p.spinner();
          fontSpinner.start('Pushing fonts');
          try {
            const result = await pushFonts(config, apiService);
            fontCount = result.count + result.skipped + result.deleted;
            const parts: string[] = [];
            if (result.count > 0) {
              parts.push(`${result.count} new`);
            }
            if (result.skipped > 0) {
              parts.push(`${result.skipped} unchanged`);
            }
            if (result.deleted > 0) {
              parts.push(`${result.deleted} deleted`);
            }
            fontSpinner.stop(
              chalk.green(
                parts.length > 0
                  ? `${parts.join(', ')} font variants updated`
                  : 'No font variants to update',
              ),
            );
            if (result.outcomes.length > 0) {
              reportResults(
                result.outcomes.map((o) => ({
                  itemName: o.itemName,
                  success: true,
                  details: [{ content: fontOutcomeLabels[o.operation] }],
                })),
                'Pushed fonts',
                'Font variant',
              );
            }
          } catch (err) {
            fontSpinner.stop(chalk.red('Font push failed'));
            throw err;
          }
        }

        // Step 5: Upload vendor/local artifacts and sync manifest
        const { artifactCount } = await syncManifestArtifacts(outputDir, {
          apiService,
          createSpinner: () => p.spinner(),
          logInfo: (msg) => p.log.info(msg),
        });

        // Validate and push pages.
        if (discoveredPages.length > 0) {
          // Validate pages against the catalog.
          const validationSpinner = p.spinner();
          validationSpinner.start(
            `Validating ${discoveredPages.length} ${pluralize(discoveredPages.length, 'page')}`,
          );

          const { results: pageValidationResults } = await validatePages(
            discoveryResult,
            { remotePageByUuid },
          );

          validationSpinner.stop(
            chalk.green(
              `Validated ${discoveredPages.length} ${pluralize(discoveredPages.length, 'page')}`,
            ),
          );

          if (pageValidationResults.some((r) => !r.success)) {
            reportResults(
              pageValidationResults,
              'Page validation results',
              'Page',
            );
            throw new Error(
              'Page validation failed. Fix the errors above before pushing.',
            );
          }

          // Prepare and push pages.
          const componentVersions = await apiService.listComponentVersions();

          const pageSpinner = p.spinner();
          pageSpinner.start('Preparing pages');

          const {
            valid: validPages,
            failed: failedPreps,
            pendingMediaReconciliations,
          } = await preparePages(
            discoveredPages,
            componentVersions,
            discoveryResult,
          );

          if (pendingMediaReconciliations.length > 0) {
            throw new Error(
              'Some pages contain media that references external URLs instead of Drupal media entities.\n' +
                'Run `npx canvas reconcile-media` to download the external media, upload them to Drupal, and replace them in page files before pushing.',
            );
          }

          if (validPages.length === 0) {
            pageSpinner.stop(chalk.yellow('No valid pages to push'));
          } else {
            const pushProgress = createProgressCallback(
              pageSpinner,
              'Pushing pages',
              validPages.length,
            );
            pageSpinner.message('Pushing pages');

            const pushResults = await pushPages(
              validPages,
              remotePageByUuid,
              apiService,
            );

            // Count progress for each successful result.
            for (const r of pushResults) {
              if (r.success) pushProgress();
            }

            pageSpinner.stop(
              chalk.green(
                `Processed ${pushResults.length} ${pluralize(pushResults.length, 'page')}`,
              ),
            );

            const pageResults = collectPageResults(
              pushResults,
              failedPreps,
              discoveredPages,
            );

            reportResults(pageResults, 'Pushed pages', 'Page');
          }
        }

        // Validate and push content templates.
        let pushedContentTemplateCount = 0;
        if (discoveredContentTemplates.length > 0) {
          const ctValidationSpinner = p.spinner();
          ctValidationSpinner.start(
            `Validating ${discoveredContentTemplates.length} ${pluralize(discoveredContentTemplates.length, 'content template')}`,
          );

          const { results: ctValidationResults } =
            await validateContentTemplates(discoveryResult, { apiService });

          ctValidationSpinner.stop(
            chalk.green(
              `Validated ${discoveredContentTemplates.length} ${pluralize(discoveredContentTemplates.length, 'content template')}`,
            ),
          );

          if (ctValidationResults.some((r) => !r.success)) {
            reportResults(
              ctValidationResults,
              'Content template validation results',
              'Content template',
            );
            throw new Error(
              'Content template validation failed. Fix the errors above before pushing.',
            );
          }

          const componentVersions = await apiService.listComponentVersions();

          const ctSpinner = p.spinner();
          ctSpinner.start('Preparing content templates');

          const { valid: validTemplates, failed: failedCtPreps } =
            await prepareContentTemplates(
              discoveredContentTemplates,
              componentVersions,
              discoveryResult,
            );

          if (validTemplates.length === 0 && failedCtPreps.length > 0) {
            ctSpinner.stop(chalk.yellow('No valid content templates to push'));
            const ctResults = collectContentTemplateResults(
              [],
              failedCtPreps,
              discoveredContentTemplates,
            );
            reportResults(
              ctResults,
              'Pushed content templates',
              'Content template',
            );
          } else if (validTemplates.length > 0) {
            const ctProgress = createProgressCallback(
              ctSpinner,
              'Pushing content templates',
              validTemplates.length,
            );
            ctSpinner.message('Pushing content templates');

            const ctPushResults = await pushContentTemplates(
              validTemplates,
              remoteContentTemplateById,
              apiService,
            );

            for (const r of ctPushResults) {
              if (r.success) ctProgress();
            }
            pushedContentTemplateCount = ctPushResults.filter(
              (r) => r.success,
            ).length;

            ctSpinner.stop(
              chalk.green(
                `Processed ${ctPushResults.length} ${pluralize(ctPushResults.length, 'content template')}`,
              ),
            );

            const ctResults = collectContentTemplateResults(
              ctPushResults,
              failedCtPreps,
              discoveredContentTemplates,
            );

            reportResults(
              ctResults,
              'Pushed content templates',
              'Content template',
            );
          } else {
            ctSpinner.stop(chalk.yellow('No valid content templates to push'));
          }
        }

        // Validate and push global regions.
        if (
          discoveredRegions.length > 0 ||
          remoteRegionNamesToDelete.length > 0
        ) {
          let validRegions: Awaited<
            ReturnType<typeof prepareRegions>
          >['valid'] = [];

          if (discoveredRegions.length > 0) {
            const regionValidationSpinner = p.spinner();
            regionValidationSpinner.start(
              `Validating ${discoveredRegions.length} global ${pluralize(discoveredRegions.length, 'region')}`,
            );

            const { results: regionValidationResults } =
              await validateRegions(discoveryResult);

            regionValidationSpinner.stop(
              chalk.green(
                `Validated ${discoveredRegions.length} global ${pluralize(discoveredRegions.length, 'region')}`,
              ),
            );

            if (regionValidationResults.some((r) => !r.success)) {
              reportResults(
                regionValidationResults,
                'Global region validation results',
                'Global region',
              );
              throw new Error(
                'Global region validation failed. Fix the errors above before pushing.',
              );
            }

            const componentVersions = await apiService.listComponentVersions();

            const regionPrepSpinner = p.spinner();
            regionPrepSpinner.start(
              `Preparing ${discoveredRegions.length} global ${pluralize(discoveredRegions.length, 'region')}`,
            );
            const { valid, failed: failedRegionPreps } = await prepareRegions(
              discoveredRegions,
              componentVersions,
              discoveryResult,
            );
            validRegions = valid;
            regionPrepSpinner.stop(
              chalk.green(
                `Prepared ${validRegions.length} global ${pluralize(validRegions.length, 'region')}`,
              ),
            );

            if (failedRegionPreps.length > 0) {
              const failedResults = collectRegionResults(
                [],
                failedRegionPreps,
                discoveredRegions,
              );
              reportResults(
                failedResults,
                'Global region validation results',
                'Global region',
              );
              throw new Error(
                'Global region validation failed. Fix the errors above before pushing.',
              );
            }
          }

          const regionPushSpinner = p.spinner();
          regionPushSpinner.start('Pushing global regions');
          const regionPushResults = await pushRegions(
            validRegions,
            remoteRegionIdsByName,
            apiService,
            remoteRegionNamesToDelete,
          );
          regionPushSpinner.stop(
            chalk.green(
              `Processed ${regionPushResults.length} global ${pluralize(regionPushResults.length, 'region')}`,
            ),
          );

          const regionResults = collectRegionResults(
            regionPushResults,
            [],
            discoveredRegions,
          );
          if (regionResults.length > 0) {
            reportResults(
              regionResults,
              'Pushed global regions',
              'Global region',
            );
          }
        }

        await apiService.signalPushComplete();
        const componentCount = components.length;
        const parts = [];
        if (componentCount > 0) {
          parts.push(`${componentCount} ${pluralizeComponent(componentCount)}`);
        }
        if (discoveredPages.length > 0) {
          parts.push(
            `${discoveredPages.length} ${pluralize(discoveredPages.length, 'page')}`,
          );
        }
        if (pushedContentTemplateCount > 0) {
          parts.push(
            `${pushedContentTemplateCount} ${pluralize(
              pushedContentTemplateCount,
              'content template',
            )}`,
          );
        }
        if (discoveredRegions.length > 0) {
          parts.push(
            `${discoveredRegions.length} global ${pluralize(discoveredRegions.length, 'region')}`,
          );
        }
        if (includeGlobalCss) {
          parts.push('global CSS');
        }
        if (artifactCount > 0) {
          parts.push(`${artifactCount} artifacts`);
        }
        if (fontCount > 0) {
          parts.push(
            `${fontCount} font ${fontCount === 1 ? 'variant' : 'variants'}`,
          );
        }

        p.outro(`⬆️ Push completed: ${parts.join(', ') || 'done'}`);
      } catch (error) {
        await apiService?.signalPushFail(
          error instanceof Error ? error.message : undefined,
        );
        if (error instanceof Error) {
          p.note(chalk.red(`Error: ${error.message}`));
        } else {
          p.note(chalk.red(`Unknown error: ${String(error)}`));
        }
        p.note(chalk.red('Push aborted'));
        process.exit(1);
      }
    });
}
