import fs from 'fs/promises';
import path from 'path';
import chalk from 'chalk';
import { Option } from 'commander';
import * as p from '@clack/prompts';
import { discoverCanvasProject } from '@drupal-canvas/discovery';

import { ensureConfig, getConfig } from '../config.js';
import {
  buildFontPushPlannedResults,
  pushFonts,
} from '../lib/fonts/font-push.js';
import { createApiService, ensureAuthConfig } from '../services/api.js';
import { analyzeAndBundleImports } from '../utils/analyze-and-bundle-imports';
import { buildTailwindForComponents } from '../utils/build-tailwind';
import {
  parseBooleanOption,
  pluralize,
  pluralizeComponent,
  updateConfigFromOptions,
} from '../utils/command-helpers';
import { generateManifest } from '../utils/generate-manifest';
import {
  collectPageResults,
  preparePages,
  pushPages,
} from '../utils/prepare-pages-push';
import {
  buildAndPushComponents,
  uploadGlobalAssetLibrary,
} from '../utils/prepare-push';
import { reportResults } from '../utils/report-results';
import { createProgressCallback, processInPool } from '../utils/request-pool';
import { validatePages } from '../utils/validate-page';

import type { Command } from 'commander';
import type { ApiService } from '../services/api.js';
import type {
  BrandKitFontEntry,
  BuildManifest,
  UploadedArtifact,
  UploadedArtifactResult,
} from '../types/Component.js';
import type { PageListItem } from '../types/Page.js';
import type { Result } from '../types/Result.js';

interface PushOptions {
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  scope?: string;
  includePages?: boolean;
  includeBrandKit?: boolean;
  dir?: string;
  yes?: boolean;
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
    files.length,
  );

  const results = await processInPool(files, async (file) => {
    const absolutePath = path.resolve(distDir, file.filePath);
    const fileBuffer = await fs.readFile(absolutePath);
    const filename = path.basename(file.filePath);

    const uploadResult: UploadedArtifactResult =
      await apiService.uploadArtifact(filename, fileBuffer);
    uploadProgress();

    return {
      entry: {
        name: file.name,
        uri: uploadResult.uri,
      } satisfies UploadedArtifact,
      type: file.type,
    };
  });

  const grouped: {
    vendor: UploadedArtifact[];
    local: UploadedArtifact[];
    shared: UploadedArtifact[];
  } = {
    vendor: [],
    local: [],
    shared: [],
  };
  const errors: string[] = [];

  for (const result of results) {
    if (result.success && result.result) {
      grouped[result.result.type].push(result.result.entry);
    } else {
      const fileName = files[result.index]?.name || 'unknown';
      errors.push(
        `Failed to upload ${fileName}: ${result.error?.message || 'Unknown error'}`,
      );
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
        updateConfigFromOptions(options);

        await ensureAuthConfig();
        await ensureConfig(['componentDir']);
        const config = getConfig();
        const { componentDir, aliasBaseDir, outputDir } = config;
        const includesPages = config.includePages;
        const includesBrandKit = config.includeBrandKit;
        const hasBrandKitFontsConfig = config.fonts !== undefined;
        // Step 1. Discover all components and pages.
        const discoveryResult = await discoverCanvasProject({
          componentRoot: componentDir,
          pagesRoot: config.pagesDir,
          projectRoot: process.cwd(),
        });
        const {
          components,
          pages: allDiscoveredPages,
          warnings,
        } = discoveryResult;
        const discoveredPages = includesPages ? allDiscoveredPages : [];
        const hasIgnoredPages = !includesPages && allDiscoveredPages.length > 0;

        if (
          components.length === 0 &&
          discoveredPages.length === 0 &&
          !(includesBrandKit && hasBrandKitFontsConfig)
        ) {
          if (hasIgnoredPages) {
            p.log.info(
              'Ignoring local pages. Use --include-pages or set CANVAS_INCLUDE_PAGES=true to push them.',
            );
          }
          p.log.warn('No components or pages found.');
          p.outro('Push aborted (nothing to push)');
          return;
        }

        if (
          components.length === 0 &&
          discoveredPages.length === 0 &&
          includesBrandKit &&
          hasBrandKitFontsConfig
        ) {
          p.log.info(
            'No components or pages found; syncing Brand Kit fonts from canvas.brand-kit.json.',
          );
        }

        if (components.length === 0) {
          p.log.info('No components found. Skipping component push.');
        }

        if (hasIgnoredPages && components.length > 0) {
          p.log.info(
            'Ignoring local pages. Use --include-pages or set CANVAS_INCLUDE_PAGES=true to push them.',
          );
        }

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

        // Step 2: Build Tailwind CSS + Global CSS
        const s2 = p.spinner();
        s2.start('Building Tailwind CSS');
        const tailwindResult = await buildTailwindForComponents(
          components,
          true,
          outputDir,
        );
        s2.stop(
          chalk.green(
            `Processed Tailwind CSS classes from ${components.length} selected local ${pluralizeComponent(components.length)} and all online components`,
          ),
        );
        reportResults([tailwindResult], 'Built assets', 'Asset');
        if (!tailwindResult.success) {
          throw new Error(
            'Tailwind build failed, global assets upload aborted. Nothing was pushed.',
          );
        }

        // Step 3: Analyze and bundle imports (vendor + local) and generate canvas-manifest.json
        const entryFiles = components
          .filter((c) => c.jsEntryPath)
          .map((c) => c.jsEntryPath as string);

        if (entryFiles.length > 0) {
          p.log.info('Analyzing and bundling imports');

          const { imports, vendorResult, localResult, sharedChunks } =
            await analyzeAndBundleImports({
              entryFiles,
              componentDir,
              aliasBaseDir,
              outputDir,
            });
          const vendorImportMap = vendorResult.importMap;
          const localImportMap = localResult.localImportMap;
          p.log.info(
            chalk.green(
              `Analyzed imports: ${imports.thirdPartyPackages.size} vendor, ${imports.aliasImports.size} local`,
            ),
          );
          if (vendorResult.success) {
            const vendorImportCount = vendorResult.bundledPackages.length;
            if (vendorImportCount > 0)
              p.log.info(
                chalk.green(
                  `Bundled ${vendorImportCount} vendor ${pluralize(vendorImportCount, 'package')} → ${outputDir}/vendor/`,
                ),
              );
          }
          if (localResult.success) {
            const bundledLocalImportCount = Object.keys(localImportMap).length;
            if (bundledLocalImportCount > 0) {
              p.log.info(
                chalk.green(
                  `Bundled ${bundledLocalImportCount} local ${pluralize(bundledLocalImportCount, 'import')} → ${outputDir}/local/`,
                ),
              );
            }
          }
          // Generate manifest for the bundled imports
          await generateManifest({
            outputDir,
            vendorImportMap,
            localImportMap,
            sharedChunks,
          });
        }

        let componentResults: Result[] = [];
        let includeGlobalCss = false;
        let fontCount = 0;

        // Build and push components
        if (components.length > 0) {
          componentResults = await buildAndPushComponents(
            components,
            apiService,
            true,
            'Pushing',
          );
          if (componentResults.some((r) => !r.success)) {
            reportResults(componentResults, 'Built components', 'Component');
            throw new Error('Component build failed. Nothing was pushed.');
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

          const { results: pageValidationResults } =
            await validatePages(discoveryResult);

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
