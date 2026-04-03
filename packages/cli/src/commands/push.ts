import fs from 'fs/promises';
import path from 'path';
import chalk from 'chalk';
import { Option } from 'commander';
import * as p from '@clack/prompts';
import { discoverCanvasProject } from '@drupal-canvas/discovery';

import { ensureConfig, getConfig } from '../config.js';
import { createApiService } from '../services/api.js';
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

import type { Command } from 'commander';
import type { ApiService } from '../services/api.js';
import type {
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
      'build and push local components, global CSS, vendor/local artifacts, and optional pages to Drupal',
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
    .option('-d, --dir <directory>', 'Component directory')
    .option('-y, --yes', 'Skip confirmation prompts')
    .action(async (options: PushOptions) => {
      let apiService: ApiService | undefined;
      try {
        p.intro(chalk.bold('Drupal Canvas CLI: push'));
        // Update config with CLI options.
        updateConfigFromOptions(options);

        await ensureConfig([
          'siteUrl',
          'clientId',
          'clientSecret',
          'scope',
          'componentDir',
        ]);
        const config = getConfig();
        const { componentDir, aliasBaseDir, outputDir } = config;
        const includesPages = config.includePages;
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

        if (components.length === 0 && discoveredPages.length === 0) {
          if (hasIgnoredPages) {
            p.log.info(
              'Ignoring local pages. Use --include-pages or set CANVAS_INCLUDE_PAGES=true to push them.',
            );
          }
          p.log.warn('No components or pages found.');
          p.outro('Push aborted (nothing to push)');
          return;
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

        // Fetch remote pages early for the planned operations summary.
        const remotePages =
          includesPages && discoveredPages.length > 0
            ? await apiService.listPages()
            : {};
        if (Object.keys(remotePages).length >= 50) {
          p.log.warn(
            chalk.yellow(
              '⚠ The API returned 50 pages (the maximum). Some existing pages may be re-created as duplicates.',
            ),
          );
        }
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

        // Step 5: Upload vendor/local artifacts and sync manifest
        await syncManifestArtifacts(outputDir, {
          apiService,
          createSpinner: () => p.spinner(),
          logInfo: (msg) => p.log.info(msg),
        });

        // Step 6: Push pages
        if (discoveredPages.length > 0) {
          const componentVersions = await apiService.listComponentVersions();

          const pageSpinner = p.spinner();
          pageSpinner.start('Preparing pages');

          const { valid: validPages, failed: failedPreps } = await preparePages(
            discoveredPages,
            componentVersions,
            discoveryResult,
          );

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
        p.outro(`⬆️ Push completed`);
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
