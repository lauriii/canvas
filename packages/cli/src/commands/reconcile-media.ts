import { createHash } from 'crypto';
import fs from 'fs/promises';
import path from 'path';
import axios from 'axios';
import chalk from 'chalk';
import { Option } from 'commander';
import { useAgent } from 'request-filtering-agent';
import * as p from '@clack/prompts';
import {
  discoverCanvasProject,
  loadComponentsMetadata,
} from '@drupal-canvas/discovery';

import { ensureConfig, getConfig } from '../config.js';
import { createApiService, ensureAuthConfig } from '../services/api.js';
import {
  applySyncOptionAliasesAndWarnings,
  parseBooleanOption,
  pluralize,
  updateConfigFromOptions,
} from '../utils/command-helpers';
import { printCommandIntro } from '../utils/command-intro';
import { getUnreconciledMedia } from '../utils/prop-transforms';
import {
  COMMAND_RESULT_REPORT_OPTIONS,
  reportResults,
} from '../utils/report-results';
import { processInPool } from '../utils/request-pool';
import { isRecord } from '../utils/utils';

import type {
  ComponentMetadata,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
import type { Command } from 'commander';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { ApiService, UploadedMedia } from '../services/api.js';
import type { Result } from '../types/Result';

interface ReconcileMediaOptions {
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  scope?: string;
  includePages?: boolean;
  includeContentTemplates?: boolean;
  pages?: boolean;
  contentTemplates?: boolean;
  pageTemplates?: boolean;
  sync?: Partial<{
    pages: boolean;
    contentTemplates: boolean;
    pageTemplates: boolean;
  }>;
  dir?: string;
  yes?: boolean;
}

export interface ReconcileMediaSyncConfig {
  includePages: boolean;
  includeContentTemplates: boolean;
  includePageTemplates: boolean;
}

export interface ReconcileSpecFile {
  label: string;
  relativePath: string;
  path: string;
  spec: { elements: AuthoredSpecElementMap; [key: string]: unknown };
}

interface DownloadedMedia {
  buffer: Buffer;
  filename: string;
  mimeType: string;
}

interface ReconciledMediaInputs {
  src: string;
  alt: string;
  width: number;
  height: number;
}

function truncateUrl(url: string): string {
  if (url.startsWith('data:')) {
    const semicolonIndex = url.indexOf(';');
    const prefix =
      semicolonIndex !== -1 ? url.slice(0, semicolonIndex) : 'data:…';
    return `${prefix};…(${url.length} chars)`;
  }
  return url;
}

function sanitizeFilename(filename: string): string {
  return filename.replace(/[^a-zA-Z0-9._-]+/g, '-');
}

const SUPPORTED_IMAGE_TYPES: Record<string, string> = {
  'image/jpeg': '.jpg',
  'image/png': '.png',
  'image/gif': '.gif',
  'image/webp': '.webp',
  'image/avif': '.avif',
};

function extensionFromContentType(contentType: string | undefined): string {
  const normalized = contentType?.split(';')[0]?.trim().toLowerCase() ?? '';
  return SUPPORTED_IMAGE_TYPES[normalized] ?? '';
}

export async function downloadExternalMedia(
  url: string,
): Promise<DownloadedMedia> {
  const agent = useAgent(url);
  const response = await axios.get<ArrayBuffer>(url, {
    responseType: 'arraybuffer',
    timeout: 30000,
    httpAgent: agent,
    httpsAgent: agent,
  });
  const contentType = response.headers['content-type'] as string | undefined;
  const mimeType = contentType?.split(';')[0]?.trim().toLowerCase() ?? '';
  const parsedUrl = new URL(url);
  const rawBaseName = path.basename(parsedUrl.pathname) || 'media';
  const extension =
    path.extname(rawBaseName) || extensionFromContentType(contentType);
  const baseName =
    path.basename(rawBaseName, path.extname(rawBaseName)) || 'media';
  const urlHash = createHash('sha256').update(url).digest('hex').slice(0, 8);
  const filename = sanitizeFilename(`${baseName}-${urlHash}${extension}`);

  return {
    buffer: Buffer.from(response.data),
    filename,
    mimeType,
  };
}

export function downloadDataUrlMedia(url: string): DownloadedMedia {
  const match = /^data:([^;,]+)?(?:;base64)?,(.*)$/i.exec(url);
  if (!match) {
    throw new Error('Invalid data URL');
  }
  const mimeType = (match[1] ?? '').toLowerCase();
  const data = match[2];
  const buffer = Buffer.from(data, 'base64');
  const extension = extensionFromContentType(mimeType) || '.bin';
  const filename = sanitizeFilename(`media${extension}`);
  return { buffer, filename, mimeType };
}

function defaultDownloadMedia(url: string): Promise<DownloadedMedia> {
  if (url.startsWith('data:')) {
    return Promise.resolve(downloadDataUrlMedia(url));
  }
  return downloadExternalMedia(url);
}

async function readReconcileSpecFile(
  label: string,
  relativePath: string,
  specPath: string,
): Promise<ReconcileSpecFile> {
  const content = await fs.readFile(specPath, 'utf-8');
  const parsedSpec = JSON.parse(content) as {
    elements?: AuthoredSpecElementMap;
    [key: string]: unknown;
  };
  return {
    label,
    relativePath,
    path: specPath,
    spec: { ...parsedSpec, elements: parsedSpec.elements ?? {} },
  };
}

export async function collectReconcileSpecFiles(
  discoveryResult: Pick<
    DiscoveryResult,
    'pages' | 'contentTemplates' | 'pageTemplates'
  >,
  syncConfig: ReconcileMediaSyncConfig,
): Promise<ReconcileSpecFile[]> {
  const specFiles: ReconcileSpecFile[] = [];

  if (syncConfig.includePages) {
    for (const page of discoveryResult.pages) {
      specFiles.push(
        await readReconcileSpecFile(
          `page "${page.name}"`,
          page.relativePath,
          page.path,
        ),
      );
    }
  }

  if (syncConfig.includeContentTemplates) {
    for (const template of discoveryResult.contentTemplates) {
      specFiles.push(
        await readReconcileSpecFile(
          `content template "${template.name}"`,
          template.relativePath,
          template.path,
        ),
      );
    }
  }

  if (syncConfig.includePageTemplates) {
    for (const pageTemplate of discoveryResult.pageTemplates) {
      specFiles.push(
        await readReconcileSpecFile(
          `page template "${pageTemplate.id}"`,
          pageTemplate.relativePath,
          pageTemplate.path,
        ),
      );
    }
  }

  return specFiles;
}

export function hasReconcileMediaSyncEnabled(
  syncConfig: ReconcileMediaSyncConfig,
): boolean {
  return (
    syncConfig.includePages ||
    syncConfig.includeContentTemplates ||
    syncConfig.includePageTemplates
  );
}

export interface ReconcileSuccess {
  elementId: string;
  propName: string;
  src: string;
  mediaId: number;
}

export interface ReconcileFailure {
  elementId: string;
  propName: string;
  src: string;
  error: string;
}

export interface ReconcileResult {
  reconciled: number;
  successes: ReconcileSuccess[];
  failures: ReconcileFailure[];
}

interface MediaWorkItem {
  elementKey: string;
  propName: string;
  externalUrl: string;
  mediaType: string;
  alt: string;
}

export async function reconcileElementMapMedia(
  elements: AuthoredSpecElementMap,
  metadata: ComponentMetadata[],
  apiService: Pick<ApiService, 'uploadMedia'>,
  downloadMedia: (
    url: string,
  ) => Promise<DownloadedMedia> = defaultDownloadMedia,
): Promise<ReconcileResult> {
  const schemaMap = new Map(
    metadata.map((m) => [`js.${m.machineName}`, m.props.properties ?? {}]),
  );

  // Collect all work items first.
  const workItems: MediaWorkItem[] = [];
  for (const [elementKey, element] of Object.entries(elements)) {
    const propSchemas = schemaMap.get(element.type);
    if (!propSchemas || !isRecord(element.props)) {
      continue;
    }

    for (const [propName, value] of Object.entries(element.props)) {
      const schema = propSchemas[propName];
      if (!schema) continue;

      const match = getUnreconciledMedia(value, schema);
      if (!match) {
        continue;
      }

      workItems.push({
        elementKey,
        propName,
        externalUrl: match.url,
        mediaType: match.mediaType,
        alt: isRecord(value) && typeof value.alt === 'string' ? value.alt : '',
      });
    }
  }

  if (workItems.length === 0) {
    return { reconciled: 0, successes: [], failures: [] };
  }

  // Deduplicate: upload each unique URL only once.
  const uniqueUrls = [...new Set(workItems.map((item) => item.externalUrl))];
  const uploadResults = await processInPool(uniqueUrls, async (url) => {
    const firstItem = workItems.find((item) => item.externalUrl === url)!;
    const downloaded = await downloadMedia(url);
    if (
      downloaded.mimeType &&
      !(downloaded.mimeType in SUPPORTED_IMAGE_TYPES)
    ) {
      throw new Error(
        `Unsupported image type "${downloaded.mimeType}". Supported types: ${Object.keys(SUPPORTED_IMAGE_TYPES).join(', ')}.`,
      );
    }
    const uploaded = await apiService.uploadMedia<ReconciledMediaInputs>({
      mediaType: firstItem.mediaType,
      filename: downloaded.filename,
      fileBuffer: downloaded.buffer,
      data: {
        title: downloaded.filename,
        alt: firstItem.alt,
      },
    });
    return uploaded;
  });

  // Index upload results by URL.
  const uploadByUrl = new Map<
    string,
    | { success: true; uploaded: UploadedMedia<ReconciledMediaInputs> }
    | { success: false; error: string }
  >();
  for (const result of uploadResults) {
    const url = uniqueUrls[result.index];
    if (result.success && result.result) {
      uploadByUrl.set(url, { success: true, uploaded: result.result });
    } else {
      uploadByUrl.set(url, {
        success: false,
        error: result.error?.message ?? 'Unknown error',
      });
    }
  }

  // Apply results back to all work items.
  let reconciled = 0;
  const successes: ReconcileSuccess[] = [];
  const failures: ReconcileFailure[] = [];
  for (const item of workItems) {
    const upload = uploadByUrl.get(item.externalUrl)!;
    if (!upload.success) {
      failures.push({
        elementId: item.elementKey,
        propName: item.propName,
        src: item.externalUrl,
        error: upload.error,
      });
      continue;
    }
    const { uploaded } = upload;
    const element = elements[item.elementKey];
    (element.props as Record<string, unknown>)[item.propName] =
      uploaded.inputs_resolved;
    element._provenance = {
      ...element._provenance,
      [item.propName]: { target_id: uploaded.id, source_url: item.externalUrl },
    };
    successes.push({
      elementId: item.elementKey,
      propName: item.propName,
      src: item.externalUrl,
      mediaId: uploaded.id,
    });
    reconciled += 1;
  }

  return { reconciled, successes, failures };
}

export function reconcileMediaCommand(program: Command): void {
  program
    .command('reconcile-media')
    .description(
      'upload supported external media from pages, content templates, and page templates to Drupal and store its provenance',
    )
    .option('--client-id <id>', 'Client ID')
    .option('--client-secret <secret>', 'Client Secret')
    .option('--site-url <url>', 'Site URL')
    .option('--scope <scope>', 'Scope')
    .addOption(
      new Option(
        '--include-pages [enabled]',
        'Include pages in the media reconciliation operation',
      )
        .preset('true')
        .argParser(parseBooleanOption)
        .default(undefined),
    )
    .addOption(
      new Option(
        '--include-content-templates [enabled]',
        'Include content templates in the media reconciliation operation',
      )
        .preset('true')
        .argParser(parseBooleanOption)
        .default(undefined),
    )
    .option(
      '--no-pages',
      'Exclude pages from the media reconciliation operation',
    )
    .option(
      '--no-content-templates',
      'Exclude content templates from the media reconciliation operation',
    )
    .option(
      '--no-page-templates',
      'Exclude page templates from the media reconciliation operation',
    )
    .option('-d, --dir <directory>', 'Component directory')
    .option('-y, --yes', 'Skip confirmation prompts')
    .action(async (options: ReconcileMediaOptions) => {
      try {
        printCommandIntro('reconcile media');
        applySyncOptionAliasesAndWarnings(options);
        updateConfigFromOptions(options);

        const currentConfig = getConfig();
        const syncConfig = {
          includePages: currentConfig.includePages,
          includeContentTemplates: currentConfig.includeContentTemplates,
          includePageTemplates: currentConfig.includePageTemplates,
        };
        if (!hasReconcileMediaSyncEnabled(syncConfig)) {
          p.log.info(
            'No pages, content templates, or page templates are enabled for media reconciliation.',
          );
          p.outro('Media reconciliation skipped');
          return;
        }

        await ensureAuthConfig();
        await ensureConfig(['componentDir']);

        const nextConfig = getConfig();
        const discoveryResult = await discoverCanvasProject({
          componentRoot: nextConfig.componentDir,
          pagesRoot: nextConfig.pagesDir,
          contentTemplatesRoot: nextConfig.contentTemplatesDir,
          pageTemplatesRoot: nextConfig.pageTemplatesDir,
          projectRoot: process.cwd(),
        });
        const componentMetadata = await loadComponentsMetadata(discoveryResult);

        const specFiles = await collectReconcileSpecFiles(
          discoveryResult,
          syncConfig,
        );

        if (specFiles.length === 0) {
          p.log.warn(
            'No local pages, content templates, or page templates found for the enabled sync settings.',
          );
          p.outro('Media reconciliation skipped');
          return;
        }

        let pendingCount = 0;
        for (const { spec } of specFiles) {
          for (const element of Object.values(spec.elements ?? {})) {
            if (!isRecord(element.props)) {
              continue;
            }
            const propSchemas = componentMetadata.find(
              (metadata) => `js.${metadata.machineName}` === element.type,
            )?.props.properties;
            if (!propSchemas) {
              continue;
            }
            for (const [propName, value] of Object.entries(element.props)) {
              const schema = propSchemas[propName];
              if (schema && getUnreconciledMedia(value, schema) !== null) {
                pendingCount += 1;
              }
            }
          }
        }

        if (pendingCount === 0) {
          p.log.info('No unreconciled media found.');
          p.outro('Media reconciliation skipped');
          return;
        }

        p.log.info(
          `Found ${pendingCount} unreconciled ${pluralize(pendingCount, 'media item', 'media items')} across ${specFiles.length} ${pluralize(specFiles.length, 'file')}.`,
        );

        if (!options.yes) {
          const confirmed = await p.confirm({
            message: `Upload media to ${nextConfig.siteUrl}?`,
            initialValue: true,
          });
          if (p.isCancel(confirmed) || !confirmed) {
            p.cancel('Operation cancelled');
            return;
          }
        }

        const apiService = await createApiService();
        const spinner = p.spinner();
        spinner.start('Reconciling media');

        const resultsByUrl = new Map<string, Result>();
        for (const specFile of specFiles) {
          const { reconciled, failures, successes } =
            await reconcileElementMapMedia(
              specFile.spec.elements ?? {},
              componentMetadata,
              apiService,
            );

          for (const success of successes) {
            const existing = resultsByUrl.get(success.src);
            const ref = `${specFile.relativePath}, element ${success.elementId}, prop ${success.propName}`;
            if (existing) {
              existing.details!.push({ content: `(${ref})` });
            } else {
              resultsByUrl.set(success.src, {
                itemName: truncateUrl(success.src),
                success: true,
                details: [
                  { content: `Uploaded as media ${success.mediaId}` },
                  { content: `(${ref})` },
                ],
              });
            }
          }

          for (const failure of failures) {
            const existing = resultsByUrl.get(failure.src);
            const ref = `${specFile.relativePath}, element ${failure.elementId}, prop ${failure.propName}`;
            if (existing && !existing.success) {
              existing.details!.push({ content: `(${ref})` });
            } else {
              resultsByUrl.set(failure.src, {
                itemName: truncateUrl(failure.src),
                success: false,
                details: [{ content: failure.error }, { content: `(${ref})` }],
              });
            }
          }

          if (reconciled === 0) {
            continue;
          }

          await fs.writeFile(
            specFile.path,
            JSON.stringify(specFile.spec, null, 2) + '\n',
            'utf-8',
          );
          spinner.message('Reconciling media');
        }

        const mediaResults = [...resultsByUrl.values()];

        spinner.stop('Reconciled media', 0);

        reportResults(
          mediaResults,
          'Reconciled media',
          'URL',
          COMMAND_RESULT_REPORT_OPTIONS,
        );

        const hasFailures = mediaResults.some((r) => !r.success);
        p.outro(
          hasFailures
            ? 'Media reconciliation incomplete'
            : 'Media reconciliation completed',
        );
        if (hasFailures) {
          process.exitCode = 1;
          return;
        }
      } catch (error) {
        if (error instanceof Error) {
          p.log.error(chalk.red(`Error: ${error.message}`));
        } else {
          p.log.error(chalk.red(`Unknown error: ${String(error)}`));
        }
        p.outro('Media reconciliation failed');
        process.exitCode = 1;
      }
    });
}
