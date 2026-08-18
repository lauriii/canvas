import fs from 'fs/promises';
import path from 'path';
import chalk from 'chalk';
import { Option } from 'commander';
import * as p from '@clack/prompts';
import {
  detectHeadlessSdk,
  discoverCanvasProject,
} from '@drupal-canvas/discovery';

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
import { printCommandIntro } from '../utils/command-intro';
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
  prepareGlobalAssetLibraryUpdate,
  pushBuiltComponents,
} from '../utils/prepare-push';
import {
  collectRegionResults,
  prepareRegions,
  pushRegions,
} from '../utils/prepare-regions-push';
import {
  formatErrorMessage,
  PushPhaseError,
  ReportedPushError,
  runPushResourcePipeline,
} from '../utils/push-resource-pipeline';
import {
  reportResults,
  splitFailedResultsByFile,
} from '../utils/report-results';
import { createProgressCallback, processInPool } from '../utils/request-pool';
import { formatFilePathForOutput } from '../utils/utils';
import { validateContentTemplates } from '../utils/validate-content-template';
import { validatePages } from '../utils/validate-page';
import { validateRegions } from '../utils/validate-region';

import type { DiscoveryWarning } from '@drupal-canvas/discovery';
import type { Command } from 'commander';
import type { ApiService } from '../services/api.js';
import type {
  AssetLibrary,
  BrandKitFontEntry,
  BuildManifest,
  UploadedArtifact,
  UploadedArtifactResult,
} from '../types/Component.js';
import type { ContentTemplateListItem } from '../types/ContentTemplate.js';
import type { PageListItem } from '../types/Page.js';
import type { Result } from '../types/Result.js';
import type { CommandSummaryResource } from '../utils/command-summary';

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

type PlannedPushResourceKey =
  | 'components'
  | 'pages'
  | 'content-templates'
  | 'global-regions'
  | 'brand-kit';

interface NotStartedPushResource {
  key: PlannedPushResourceKey;
  label: string;
  operations: Map<string, number>;
}

const PLANNED_OPERATION_ORDER = ['create', 'update', 'delete'];
const SUMMARY_CHILD_INDENT = '  ';
const SUMMARY_DETAIL_INDENT = '    ';
const PUSH_REPORT_OPTIONS = {
  showTitle: false,
  indent: false,
  failureStyle: 'inline' as const,
};

class ArtifactUploadError extends Error {
  constructor(public readonly failedResults: Result[]) {
    super(
      [
        'Some uploads failed:',
        ...failedResults.map(formatArtifactUploadFailureMessage),
      ].join('\n'),
    );
    this.name = 'ArtifactUploadError';
  }
}

function comparePlannedOperations(a: string, b: string): number {
  const aIndex = PLANNED_OPERATION_ORDER.indexOf(a);
  const bIndex = PLANNED_OPERATION_ORDER.indexOf(b);
  return (
    (aIndex === -1 ? PLANNED_OPERATION_ORDER.length : aIndex) -
      (bIndex === -1 ? PLANNED_OPERATION_ORDER.length : bIndex) ||
    a.localeCompare(b)
  );
}

function formatNotStartedResource(resource: NotStartedPushResource): string {
  const operations = [...resource.operations.entries()]
    .sort(([a], [b]) => comparePlannedOperations(a, b))
    .map(([operation, count]) => `${count} ${operation}`)
    .join(', ');
  return `${resource.label}: ${operations}`;
}

function removeNotStartedResource(
  resources: NotStartedPushResource[],
  key: PlannedPushResourceKey,
): void {
  const index = resources.findIndex((resource) => resource.key === key);
  if (index !== -1) {
    resources.splice(index, 1);
  }
}

function buildNotStartedResources(
  plannedResults: Result[],
): NotStartedPushResource[] {
  const resourceDefinitions: Array<{
    key: PlannedPushResourceKey;
    label: string;
    itemType: string;
  }> = [
    { key: 'components', label: 'Components', itemType: 'Component' },
    { key: 'pages', label: 'Pages', itemType: 'Page' },
    {
      key: 'content-templates',
      label: 'Content templates',
      itemType: 'Content template',
    },
    {
      key: 'global-regions',
      label: 'Global regions',
      itemType: 'Global region',
    },
    { key: 'brand-kit', label: 'brand kit', itemType: 'Font variant' },
  ];

  return resourceDefinitions
    .map((definition) => {
      const operations = new Map<string, number>();
      for (const result of plannedResults) {
        if (result.itemType !== definition.itemType) {
          continue;
        }
        const operation = result.details?.[0]?.content.trim();
        if (!operation) {
          continue;
        }
        operations.set(operation, (operations.get(operation) ?? 0) + 1);
      }
      return operations.size > 0
        ? {
            key: definition.key,
            label: definition.label,
            operations,
          }
        : null;
    })
    .filter((resource): resource is NotStartedPushResource =>
      Boolean(resource),
    );
}

export function formatDiscoveryWarning(warning: DiscoveryWarning): string {
  const location = warning.path
    ? ` (${formatFilePathForOutput(warning.path)})`
    : '';
  return `${warning.message}${location}`;
}

export function formatDiscoveryWarningReport(
  warnings: DiscoveryWarning[],
): string | null {
  if (warnings.length === 0) {
    return null;
  }

  return [
    'Warnings',
    ...warnings.map(
      (warning) =>
        `${SUMMARY_CHILD_INDENT}${chalk.yellow('!')} ${formatDiscoveryWarning(warning)}`,
    ),
  ].join('\n');
}

function reportDiscoveryWarnings(warnings: DiscoveryWarning[]): void {
  const report = formatDiscoveryWarningReport(warnings);
  if (report) {
    p.log.message(report);
  }
}

function formatArtifactUploadFailureMessage(result: Result): string {
  const message = result.details?.[0]?.content ?? 'Unknown error';
  return `Failed to upload ${result.itemName}: ${message}`;
}

function reportPushFailure(
  error: unknown,
  completedResources: CommandSummaryResource[],
  notStartedResources: NotStartedPushResource[],
): void {
  const message = formatErrorMessage(error);
  const isAuthFailure =
    !(error instanceof PushPhaseError) &&
    (message.includes('Authentication Error') ||
      message.includes('Authentication failed'));
  const phase =
    error instanceof PushPhaseError
      ? error.phase
      : isAuthFailure
        ? 'Authentication failed'
        : 'Push failed';
  const lines: string[] = [];

  if (completedResources.length > 0 && notStartedResources.length > 0) {
    lines.push('Not started');
    for (const resource of notStartedResources) {
      lines.push(
        `${SUMMARY_CHILD_INDENT}${formatNotStartedResource(resource)}`,
      );
    }
  }

  if (!(error instanceof ReportedPushError)) {
    const failedResults =
      error instanceof PushPhaseError && error.failedResults.length > 0
        ? error.failedResults
        : [
            {
              itemName: phase,
              success: false,
              details: [{ content: message }],
            },
          ];

    if (lines.length > 0) {
      lines.push('');
    }
    lines.push('Failed');
    for (const result of failedResults) {
      lines.push(`${SUMMARY_CHILD_INDENT}${chalk.red('✗')} ${result.itemName}`);
      for (const detail of result.details ?? []) {
        lines.push(
          ...detail.content
            .split('\n')
            .map((line) => `${SUMMARY_DETAIL_INDENT}${line}`),
        );
      }
    }
  }

  if (lines.length > 0) {
    p.log.message(lines.join('\n'));
  }
  p.outro(
    completedResources.length > 0
      ? `${chalk.red('✗')} Push incomplete`
      : `${chalk.red('✗')} Push failed`,
  );
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
  path?: string;
  source?: string;
}> {
  const files: Array<{
    name: string;
    filePath: string;
    type: 'vendor' | 'local' | 'shared';
    path?: string;
    source?: string;
  }> = [];

  for (const [specifier, filePath] of Object.entries(manifest.vendor)) {
    files.push({ name: specifier, filePath, type: 'vendor' as const });
  }

  for (const [specifier, filePath] of Object.entries(manifest.local)) {
    // Carry the original disk path and (for text modules) the verbatim source
    // so a subsequent pull can reconstruct the source file on disk.
    const meta = manifest.localSources?.[specifier];
    files.push({
      name: specifier,
      filePath,
      type: 'local' as const,
      ...(meta?.path !== undefined ? { path: meta.path } : {}),
      ...(meta?.source !== undefined ? { source: meta.source } : {}),
    });
  }

  // Add shared chunks - use filePath as the name since they don't have import specifiers
  for (const filePath of manifest.shared ?? []) {
    files.push({ name: filePath, filePath, type: 'shared' as const });
  }

  return files;
}

interface GroupedUploadedManifest {
  vendor: UploadedArtifact[];
  local: UploadedArtifact[];
  shared: UploadedArtifact[];
  // Verbatim sources of local modules bundled into other artifacts. Not
  // uploaded as file artifacts (there is no artifact); sent as-is so a pull can
  // restore the editable file. Never part of the runtime import map.
  bundledSources: Array<{ path: string; source: string }>;
}

/**
 * Uploads artifact files and builds manifest entries from the results.
 */
async function uploadAndBuildManifest(
  files: Array<{
    name: string;
    filePath: string;
    type: 'vendor' | 'local' | 'shared';
    path?: string;
    source?: string;
  }>,
  distDir: string,
  apiService: Pick<ApiService, 'uploadArtifact'>,
  spinner: { message: (msg?: string) => void },
): Promise<GroupedUploadedManifest> {
  const uploadProgress = createProgressCallback(
    spinner,
    'Pushing dependencies',
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
  const failedResults: Result[] = [];

  for (const result of results) {
    if (result.success && result.result) {
      uploadedByFilePath.set(uniqueFiles[result.index].filePath, result.result);
    } else {
      const fileName = uniqueFiles[result.index]?.name || 'unknown';
      failedResults.push({
        itemName: fileName,
        itemType: 'Artifact',
        success: false,
        details: [{ content: result.error?.message || 'Unknown error' }],
      });
    }
  }

  const grouped: GroupedUploadedManifest = {
    vendor: [],
    local: [],
    shared: [],
    bundledSources: [],
  };

  if (failedResults.length === 0) {
    for (const file of files) {
      const uploadResult = uploadedByFilePath.get(file.filePath);
      if (uploadResult) {
        // Only local entries carry path/source; vendor and shared stay
        // {name, uri}.
        grouped[file.type].push({
          name: file.name,
          uri: uploadResult.uri,
          ...(file.path !== undefined ? { path: file.path } : {}),
          ...(file.source !== undefined ? { source: file.source } : {}),
        });
      }
    }
  }

  if (failedResults.length > 0) {
    throw new ArtifactUploadError(failedResults);
  }

  return grouped;
}

/**
 * Uploads build artifacts from manifest.
 */
export async function uploadManifestArtifacts(
  outputDir: string,
  options: {
    apiService: Pick<ApiService, 'uploadArtifact'>;
    createSpinner?: () => {
      start: (msg?: string) => void;
      stop: (msg?: string, code?: number) => void;
      message: (msg?: string) => void;
    };
    logInfo?: (msg: string) => void;
  },
): Promise<{
  artifactCount: number;
  groupedManifest: GroupedUploadedManifest;
}> {
  const createSpinner = options.createSpinner ?? (() => p.spinner());
  const emptyManifest: GroupedUploadedManifest = {
    vendor: [],
    local: [],
    shared: [],
    bundledSources: [],
  };

  const artifactFiles: Array<{
    name: string;
    filePath: string;
    type: 'vendor' | 'local' | 'shared';
  }> = [];
  // Sources of bundled local modules, carried verbatim (no artifact to upload).
  let bundledSources: Array<{ path: string; source: string }> = [];
  try {
    const manifest = await readBuildManifest(outputDir);
    artifactFiles.push(...collectManifestArtifacts(manifest));
    bundledSources = manifest.bundledSources ?? [];
  } catch {
    // Build manifest may not exist if build wasn't run. This is not fatal once
    // components have already been pushed.
    options.logInfo?.(
      'No dependency map found, skipping component dependency upload',
    );
  }

  if (artifactFiles.length === 0) {
    options.logInfo?.('No component dependencies to upload');
    return {
      artifactCount: 0,
      groupedManifest: { ...emptyManifest, bundledSources },
    };
  }

  const dependencySpinner = createSpinner();
  dependencySpinner.start('Pushing dependencies');

  const groupedManifest = await uploadAndBuildManifest(
    artifactFiles,
    outputDir,
    options.apiService,
    dependencySpinner,
  ).catch((error) => {
    dependencySpinner.stop('Pushed dependencies', 2);
    throw error;
  });
  groupedManifest.bundledSources = bundledSources;
  const artifactCount =
    groupedManifest.vendor.length +
    groupedManifest.local.length +
    groupedManifest.shared.length;
  dependencySpinner.stop('Pushed dependencies', 0);

  return { artifactCount, groupedManifest };
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
      stop: (msg?: string, code?: number) => void;
      message: (msg?: string) => void;
    };
    logInfo?: (msg: string) => void;
  },
): Promise<{
  artifactCount: number;
  groupedManifest: GroupedUploadedManifest;
}> {
  const result = await uploadManifestArtifacts(outputDir, options);
  if (result.artifactCount === 0) {
    return result;
  }

  await options.apiService.syncManifest({
    vendor: result.groupedManifest.vendor,
    local: result.groupedManifest.local,
    shared: result.groupedManifest.shared,
  });

  return result;
}

export async function updateGlobalAssetLibraryForPush(
  apiService: Pick<
    ApiService,
    'getGlobalAssetLibrary' | 'updateGlobalAssetLibrary'
  >,
  globalAssetLibraryUpdate: Partial<AssetLibrary> | undefined,
  manifestSyncResult: {
    artifactCount: number;
    groupedManifest: GroupedUploadedManifest;
  },
): Promise<void> {
  const assetLibraryPatch: Partial<AssetLibrary> = {
    ...(globalAssetLibraryUpdate ?? {}),
  };
  // Always send the current manifest groups, even when empty since empty
  // arrays lets the backend clear them.
  assetLibraryPatch.imports = manifestSyncResult.groupedManifest.vendor;
  assetLibraryPatch.assets = manifestSyncResult.groupedManifest.local;
  assetLibraryPatch.shared = manifestSyncResult.groupedManifest.shared;
  assetLibraryPatch.bundledSources =
    manifestSyncResult.groupedManifest.bundledSources;

  const currentAssetLibrary = await apiService.getGlobalAssetLibrary();
  const supportsCodebaseSync =
    Object.hasOwn(currentAssetLibrary, 'bundledSources') &&
    Object.hasOwn(currentAssetLibrary, 'packageJson');

  if (!supportsCodebaseSync) {
    delete assetLibraryPatch.bundledSources;
    delete assetLibraryPatch.packageJson;
    assetLibraryPatch.assets = assetLibraryPatch.assets?.map(
      ({ name, uri }) => ({ name, uri }),
    );
  }

  await apiService.updateGlobalAssetLibrary(assetLibraryPatch);
}

function buildDependencyResults(
  groupedManifest: GroupedUploadedManifest,
): Result[] {
  return [
    ...groupedManifest.vendor.map((dependency) => ({
      itemName: dependency.name,
      itemType: 'Dependency',
      success: true,
      details: [{ content: 'Third-party' }],
    })),
    ...groupedManifest.local.map((dependency) => ({
      itemName: dependency.name,
      itemType: 'Dependency',
      success: true,
      details: [{ content: 'Local' }],
    })),
  ];
}

/**
 * Registers the push command.
 *
 * Pushes local components, global CSS, component dependencies, and content to Drupal.
 * 1. Component configs (via js_component entities)
 * 2. Global CSS/JS (via asset_library)
 * 3. Component dependencies (uploaded as files, tracked in manifest)
 */
export function pushCommand(program: Command): void {
  program
    .command('push')
    .description(
      'build and push local components, global CSS, component dependencies, and optional fonts and content to Drupal',
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
      const completedResources: CommandSummaryResource[] = [];
      const notStartedResources: NotStartedPushResource[] = [];
      try {
        printCommandIntro('push');
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
        // When the Canvas Headless SDK is installed, components are pushed as
        // external components (metadata only): the headless app renders them.
        // The app's entries may be framework single-file components (.vue,
        // .astro, .svelte) that the Canvas build pipeline cannot compile, so
        // discovery must not require a JavaScript entry in this mode.
        const headlessSdkDetected = detectHeadlessSdk(process.cwd());
        // Step 1. Discover all components, pages, content templates and regions.
        const discoveryResult = await discoverCanvasProject({
          componentRoot: componentDir,
          pagesRoot: config.pagesDir,
          contentTemplatesRoot: config.contentTemplatesDir,
          regionsRoot: config.regionsDir,
          projectRoot: process.cwd(),
          requireJsEntry: !headlessSdkDetected,
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
        const componentDiscoveryWarnings =
          components.length > 0 ? warnings : [];
        const immediateDiscoveryWarnings =
          components.length > 0 ? [] : warnings;
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
          p.outro('Nothing to push');
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
            'No components, pages, content templates, or global regions found; syncing brand kit fonts from canvas.brand-kit.json.',
          );
        }

        if (components.length === 0) {
          p.log.info(
            'No components found. Skipping component and global CSS push.',
          );
        }

        logIgnoredLocalResources();

        apiService = await createApiService();
        const pushApiService = apiService;
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
          create: 'create',
          update: 'update',
          delete: 'delete',
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
          notStartedResources.push(...buildNotStartedResources(plannedResults));
          reportResults(plannedResults, 'Plan', 'Item', {
            preview: true,
          });
        }

        for (const warning of immediateDiscoveryWarnings) {
          p.log.warn(formatDiscoveryWarning(warning));
        }

        if (headlessSdkDetected && components.length > 0) {
          p.log.info(
            'Canvas Headless SDK detected: components are pushed as external (metadata only).',
          );
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
            parts.push('brand kit fonts (canvas.brand-kit.json)');
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

        let componentResults: Result[] = [];
        let globalCssResult: Result | undefined;
        let globalAssetLibraryUpdate: Partial<AssetLibrary> | undefined;
        let fontCount = 0;

        if (components.length > 0) {
          removeNotStartedResource(notStartedResources, 'components');
          const componentPushApiService = apiService;
          // Step 2: Build components, Tailwind CSS, and dependency artifacts.
          const componentSpinner = p.spinner();
          componentSpinner.start('Pushing components');
          const canvasBuild = await buildCanvasProject({
            projectRoot: process.cwd(),
            componentDir,
            aliasBaseDir,
            outputDir,
            discoveryResult,
            cleanOutputDir: true,
            requireJsEntries: true,
            headlessSdkDetected,
          }).catch((error) => {
            const message = formatErrorMessage(error);
            if (message.startsWith('Missing local Tailwind CSS file')) {
              componentSpinner.stop('Pushed assets', 2);
              reportResults(
                [
                  {
                    itemName: 'Tailwind CSS',
                    itemType: 'Asset',
                    success: false,
                    details: [{ content: message }],
                  },
                ],
                'Pushed assets',
                'Asset',
                PUSH_REPORT_OPTIONS,
              );
              reportDiscoveryWarnings(componentDiscoveryWarnings);
              throw new ReportedPushError(
                'Tailwind build failed',
                'Tailwind build failed, global assets upload aborted. Nothing was pushed.',
              );
            }
            componentSpinner.stop('Build failed', 2);
            reportDiscoveryWarnings(componentDiscoveryWarnings);
            throw new PushPhaseError('Build failed', message);
          });

          if (canvasBuild.componentResults.some((r) => !r.success)) {
            componentSpinner.stop('Build failed', 2);
            reportResults(
              splitFailedResultsByFile(
                canvasBuild.componentResults.filter(
                  (result) => !result.success,
                ),
              ),
              'Build failed',
              'Component',
              PUSH_REPORT_OPTIONS,
            );
            reportDiscoveryWarnings(componentDiscoveryWarnings);
            throw new ReportedPushError(
              'Build failed',
              'Component build failed. Nothing was pushed.',
            );
          }

          if (!canvasBuild.tailwindResult.success) {
            componentSpinner.stop('Pushed assets', 2);
            reportResults(
              [canvasBuild.tailwindResult],
              'Pushed assets',
              'Asset',
              PUSH_REPORT_OPTIONS,
            );
            reportDiscoveryWarnings(componentDiscoveryWarnings);
            throw new ReportedPushError(
              'Build failed',
              'Tailwind build failed, global assets upload aborted. Nothing was pushed.',
            );
          }

          // Build and push components.
          try {
            componentResults = await pushBuiltComponents(
              canvasBuild.builtComponents,
              componentPushApiService,
              'Pushing',
              componentSpinner,
            );
          } catch (error) {
            reportDiscoveryWarnings(componentDiscoveryWarnings);
            throw new PushPhaseError(
              'Component upload failed',
              formatErrorMessage(error),
            );
          }
          if (componentResults.some((r) => !r.success)) {
            reportResults(
              componentResults,
              'Pushed components',
              'Component',
              PUSH_REPORT_OPTIONS,
            );
            reportDiscoveryWarnings(componentDiscoveryWarnings);
            throw new ReportedPushError(
              'Component push failed',
              'Component push failed. Nothing else was pushed.',
            );
          }
          reportResults(
            componentResults,
            'Pushed components',
            'Component',
            PUSH_REPORT_OPTIONS,
          );
          reportDiscoveryWarnings(componentDiscoveryWarnings);
          completedResources.push({
            label: 'Components',
            count: componentResults.length,
            unit: 'component',
            action: 'pushed',
          });

          // Prepare the global Tailwind CSS update. The final asset library
          // PATCH is sent after dependency artifacts are uploaded so CSS and
          // manifest fields land in the same config entity update.
          const assetSpinner = p.spinner();
          assetSpinner.start('Preparing assets');
          const preparedGlobalAssetLibrary =
            await prepareGlobalAssetLibraryUpdate(
              config.outputDir,
              process.cwd(),
            );
          globalCssResult = preparedGlobalAssetLibrary.result;
          globalAssetLibraryUpdate = preparedGlobalAssetLibrary.assetLibrary;
          assetSpinner.stop(
            globalCssResult.success
              ? 'Prepared assets'
              : 'Global CSS push failed',
            globalCssResult.success ? 0 : 2,
          );
          if (!globalCssResult.success) {
            reportResults([globalCssResult], 'Pushed assets', 'Asset', {
              ...PUSH_REPORT_OPTIONS,
              showSuccessHeading: false,
            });
            throw new ReportedPushError(
              'Global CSS push failed',
              globalCssResult.details?.[0]?.content ??
                'Global CSS push failed.',
            );
          }
        }

        // Step 4b: Push fonts from canvas.brand-kit.json (when configured)
        if (includesBrandKit && config.fonts) {
          removeNotStartedResource(notStartedResources, 'brand-kit');
          const fontOutcomeLabels: Record<string, string> = {
            create: chalk.green('Created'),
            update: chalk.cyan('Updated'),
            delete: chalk.red('Deleted'),
            unchanged: chalk.dim('Unchanged'),
          };
          const fontSpinner = p.spinner();
          fontSpinner.start('Pushing brand kit');
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
              parts.length > 0
                ? 'Pushed brand kit'
                : 'No brand kit font variants to update',
              0,
            );
            if (result.outcomes.length > 0) {
              reportResults(
                result.outcomes.map((o) => ({
                  itemName: o.itemName,
                  success: true,
                  details: [{ content: fontOutcomeLabels[o.operation] }],
                })),
                'Pushed brand kit',
                'Font variant',
                PUSH_REPORT_OPTIONS,
              );
            }
            if (fontCount > 0) {
              completedResources.push({
                label: 'brand kit',
                count: fontCount,
                unit: 'font variant',
                action: 'pushed',
              });
            }
          } catch (err) {
            fontSpinner.stop('Brand kit push failed', 2);
            throw new PushPhaseError(
              'Brand kit push failed',
              formatErrorMessage(err),
            );
          }
        }

        if (components.length > 0) {
          // Upload component dependencies and prepare the dependency map.
          const manifestSyncResult = await uploadManifestArtifacts(outputDir, {
            apiService,
          }).catch((error) => {
            throw new PushPhaseError(
              'Component dependency upload failed',
              formatErrorMessage(error),
              error instanceof ArtifactUploadError ? error.failedResults : [],
            );
          });
          const dependencyResults = buildDependencyResults(
            manifestSyncResult.groupedManifest,
          );
          if (dependencyResults.length > 0) {
            reportResults(
              dependencyResults,
              'Pushed dependencies',
              'Dependency',
              PUSH_REPORT_OPTIONS,
            );
            completedResources.push({
              label: 'Dependencies',
              count: dependencyResults.length,
              unit: 'dependency',
              unitPlural: 'dependencies',
              action: 'pushed',
            });
          }

          const assetSpinner = p.spinner();
          assetSpinner.start('Pushing assets');
          try {
            await updateGlobalAssetLibraryForPush(
              apiService,
              globalAssetLibraryUpdate,
              manifestSyncResult,
            );
          } catch (error) {
            assetSpinner.stop('Assets push failed', 2);
            throw new PushPhaseError(
              'Assets push failed',
              formatErrorMessage(error),
            );
          }
          assetSpinner.stop('Pushed assets', 0);
          if (globalCssResult) {
            reportResults([globalCssResult], 'Pushed assets', 'Asset', {
              ...PUSH_REPORT_OPTIONS,
              showSuccessHeading: false,
            });
          }
          completedResources.push({
            label: 'Global CSS',
            action: 'pushed',
          });
        }

        // Validate and push pages.
        if (discoveredPages.length > 0) {
          const pageSummary = await runPushResourcePipeline({
            labels: {
              start: 'Pushing pages',
              validating: 'Validating pages',
              preparing: 'Preparing pages',
              pushing: 'Pushing pages',
              done: 'Pushed pages',
            },
            phases: {
              validation: 'Page validation failed',
              preparation: 'Page preparation failed',
              push: 'Page push failed',
            },
            messages: {
              validation: 'Page validation failed.',
              noValidItems: 'No valid pages to push.',
              push: 'Some pages failed to push.',
            },
            itemLabel: 'Page',
            validate: async () =>
              (await validatePages(discoveryResult, { remotePageByUuid }))
                .results,
            markStarted: () =>
              removeNotStartedResource(notStartedResources, 'pages'),
            prepare: async () => {
              const componentVersions =
                await pushApiService.listComponentVersions();
              return preparePages(
                discoveredPages,
                componentVersions,
                discoveryResult,
              );
            },
            push: (validPages) =>
              pushPages(validPages, remotePageByUuid, pushApiService),
            collectResults: (pushResults, failedPreps) =>
              collectPageResults(pushResults, failedPreps, discoveredPages),
            reportOptions: PUSH_REPORT_OPTIONS,
            summary: {
              label: 'Pages',
              unit: 'page',
            },
          });
          if (pageSummary) {
            completedResources.push(pageSummary);
          }
        }

        // Validate and push content templates.
        if (discoveredContentTemplates.length > 0) {
          const contentTemplateSummary = await runPushResourcePipeline({
            labels: {
              start: 'Pushing content templates',
              validating: 'Validating content templates',
              preparing: 'Preparing content templates',
              pushing: 'Pushing content templates',
              done: 'Pushed content templates',
              empty: 'No valid content templates to push',
            },
            phases: {
              validation: 'Content template validation failed',
              preparation: 'Content template preparation failed',
              push: 'Content template push failed',
            },
            messages: {
              validation: 'Content template validation failed.',
              noValidItems: 'No valid content templates to push.',
              push: 'Some content templates failed to push.',
            },
            itemLabel: 'Content template',
            validate: async () =>
              (
                await validateContentTemplates(discoveryResult, {
                  apiService: pushApiService,
                })
              ).results,
            markStarted: () =>
              removeNotStartedResource(
                notStartedResources,
                'content-templates',
              ),
            prepare: async () => {
              const componentVersions =
                await pushApiService.listComponentVersions();
              return prepareContentTemplates(
                discoveredContentTemplates,
                componentVersions,
                discoveryResult,
              );
            },
            push: (validTemplates) =>
              pushContentTemplates(
                validTemplates,
                remoteContentTemplateById,
                pushApiService,
              ),
            collectResults: (pushResults, failedPreps) =>
              collectContentTemplateResults(
                pushResults,
                failedPreps,
                discoveredContentTemplates,
              ),
            reportOptions: PUSH_REPORT_OPTIONS,
            summary: {
              label: 'Content templates',
              unit: 'content template',
            },
          });
          if (contentTemplateSummary) {
            completedResources.push(contentTemplateSummary);
          }
        }

        // Validate and push global regions.
        if (
          discoveredRegions.length > 0 ||
          remoteRegionNamesToDelete.length > 0
        ) {
          const hasLocalRegions = discoveredRegions.length > 0;
          const regionSummary = await runPushResourcePipeline({
            labels: {
              start: 'Pushing global regions',
              validating: 'Validating global regions',
              preparing: 'Preparing global regions',
              pushing: 'Pushing global regions',
              done: 'Pushed global regions',
            },
            phases: {
              validation: 'Global region validation failed',
              preparation: 'Global region preparation failed',
              push: 'Global region push failed',
            },
            messages: {
              validation: 'Global region validation failed.',
              preparation: 'Global region preparation failed.',
              noValidItems: 'No valid global regions to push.',
              push: 'Some global regions failed to push.',
            },
            itemLabel: 'Global region',
            validate: hasLocalRegions
              ? async () => (await validateRegions(discoveryResult)).results
              : undefined,
            markStarted: () =>
              removeNotStartedResource(notStartedResources, 'global-regions'),
            prepare: hasLocalRegions
              ? async () => {
                  const componentVersions =
                    await pushApiService.listComponentVersions();
                  return prepareRegions(
                    discoveredRegions,
                    componentVersions,
                    discoveryResult,
                  );
                }
              : undefined,
            failOnPreparationFailures: true,
            hasPushWork: (validRegions) =>
              validRegions.length > 0 || remoteRegionNamesToDelete.length > 0,
            push: (validRegions) =>
              pushRegions(
                validRegions,
                remoteRegionIdsByName,
                pushApiService,
                remoteRegionNamesToDelete,
              ),
            collectResults: (pushResults, failedPreps) =>
              collectRegionResults(pushResults, failedPreps, discoveredRegions),
            reportOptions: PUSH_REPORT_OPTIONS,
            summary: {
              label: 'Global regions',
              unit: 'global region',
            },
          });
          if (regionSummary) {
            completedResources.push(regionSummary);
          }
        }

        await apiService.signalPushComplete();
        p.outro(`${chalk.green('✓')} Push completed`);
      } catch (error) {
        await apiService?.signalPushFail(
          error instanceof Error ? error.message : undefined,
        );
        reportPushFailure(error, completedResources, notStartedResources);
        process.exit(1);
      }
    });
}
