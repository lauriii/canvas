import fs from 'fs/promises';
import path from 'path';
import axios from 'axios';
import chalk from 'chalk';
import { useAgent } from 'request-filtering-agent';
import * as p from '@clack/prompts';
import {
  discoverCanvasProject,
  loadComponentsMetadata,
} from '@drupal-canvas/discovery';

import { ensureConfig, getConfig } from '../config.js';
import { createApiService } from '../services/api.js';
import { pluralize, updateConfigFromOptions } from '../utils/command-helpers';
import { getUnreconciledMedia } from '../utils/prop-transforms';
import { reportResults } from '../utils/report-results';
import { processInPool } from '../utils/request-pool';
import { isRecord } from '../utils/utils';

import type { ComponentMetadata } from '@drupal-canvas/discovery';
import type { Command } from 'commander';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { ApiService, UploadedMedia } from '../services/api.js';
import type { Result } from '../types/Result';

interface ReconcileMediaOptions {
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  scope?: string;
  dir?: string;
  yes?: boolean;
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
  const filename = sanitizeFilename(`${baseName}${extension}`);

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
      'upload supported external page media to Drupal and store its provenance',
    )
    .option('--client-id <id>', 'Client ID')
    .option('--client-secret <secret>', 'Client Secret')
    .option('--site-url <url>', 'Site URL')
    .option('--scope <scope>', 'Scope')
    .option('-d, --dir <directory>', 'Component directory')
    .option('-y, --yes', 'Skip confirmation prompts')
    .action(async (options: ReconcileMediaOptions) => {
      try {
        p.intro(chalk.bold('Drupal Canvas CLI: reconcile media'));
        updateConfigFromOptions(options);

        await ensureConfig([
          'siteUrl',
          'clientId',
          'clientSecret',
          'scope',
          'componentDir',
        ]);

        const nextConfig = getConfig();
        const discoveryResult = await discoverCanvasProject({
          componentRoot: nextConfig.componentDir,
          pagesRoot: nextConfig.pagesDir,
          projectRoot: process.cwd(),
        });
        const componentMetadata = await loadComponentsMetadata(discoveryResult);

        if (discoveryResult.pages.length === 0) {
          p.log.warn('No local pages found.');
          p.outro('Media reconciliation skipped');
          return;
        }

        const pageSpecs = await Promise.all(
          discoveryResult.pages.map(async (page) => {
            const content = await fs.readFile(page.path, 'utf-8');
            const spec = JSON.parse(content) as {
              title: string;
              elements: AuthoredSpecElementMap;
            };
            return { page, spec };
          }),
        );

        let pendingCount = 0;
        for (const { spec } of pageSpecs) {
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
          `Found ${pendingCount} unreconciled ${pluralize(pendingCount, 'media item', 'media items')} across ${pageSpecs.length} ${pluralize(pageSpecs.length, 'page')}.`,
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

        let reconciledCount = 0;
        const resultsByUrl = new Map<string, Result>();
        for (const { page, spec } of pageSpecs) {
          const { reconciled, failures, successes } =
            await reconcileElementMapMedia(
              spec.elements ?? {},
              componentMetadata,
              apiService,
            );

          for (const success of successes) {
            const existing = resultsByUrl.get(success.src);
            const ref = `${page.relativePath}, element ${success.elementId}, prop ${success.propName}`;
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
            const ref = `${page.relativePath}, element ${failure.elementId}, prop ${failure.propName}`;
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

          reconciledCount += reconciled;
          await fs.writeFile(
            page.path,
            JSON.stringify(spec, null, 2) + '\n',
            'utf-8',
          );
          spinner.message(
            `Reconciled ${reconciledCount}/${pendingCount} media items`,
          );
        }

        const mediaResults = [...resultsByUrl.values()];

        spinner.stop(
          chalk.green(
            `Reconciled ${reconciledCount} ${pluralize(reconciledCount, 'media item', 'media items')}`,
          ),
        );

        reportResults(mediaResults, 'Reconciled media', 'URL');

        p.outro(
          mediaResults.some((r) => !r.success)
            ? 'Media reconciliation completed with errors'
            : 'Media reconciliation completed',
        );
      } catch (error) {
        if (error instanceof Error) {
          p.note(chalk.red(`Error: ${error.message}`));
        } else {
          p.note(chalk.red(`Unknown error: ${String(error)}`));
        }
        p.note(chalk.red('Media reconciliation aborted'));
        process.exit(1);
      }
    });
}
