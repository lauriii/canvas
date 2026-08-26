import fs from 'fs/promises';
import { loadComponentsMetadata } from '@drupal-canvas/discovery';

import { authoredElementMapToComponentTree } from './authored-elements';
import {
  formatPagePathAliasChangeError,
  getPathAliasChange,
  normalizePathAlias,
} from './page-path-alias-validation';
import { pageResultName } from './page-result-name';
import { serializeElementMapForServer } from './prop-transforms';
import { processInPool } from './request-pool';

import type { DiscoveredPage, DiscoveryResult } from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { ApiService } from '../services/api';
import type { Page, PageListItem } from '../types/Page';
import type { Result } from '../types/Result';

export interface PagePushResult {
  title: string;
  operation: 'Created' | 'Updated';
}

export interface PagePushOperationResult {
  success: boolean;
  result?: PagePushResult;
  error?: Error;
  index: number;
  pageTitle?: string;
}

interface PagePreparationError extends Error {
  pageTitle?: string;
}

export interface PagePreparationFailure {
  index: number;
  error: Error;
  pageTitle?: string;
}

export interface PreparedPage {
  uuid: string | null;
  title: string;
  description: string;
  pageVariant: string | null;
  path: string;
  components: Page['components'];
  filePath: string;
}

/**
 * Prepares local pages for pushing by reading their specs and converting
 * elements to component trees.
 */
export async function preparePages(
  discoveredPages: DiscoveredPage[],
  componentVersions: Map<string, string>,
  discoveryResult: DiscoveryResult,
): Promise<{
  valid: Array<{ index: number; result: PreparedPage }>;
  failed: PagePreparationFailure[];
}> {
  const componentMetadata = await loadComponentsMetadata(discoveryResult);

  const results = await processInPool(discoveredPages, async (localPage) => {
    let pageTitle: string | undefined;
    try {
      const fileContent = await fs.readFile(localPage.path, 'utf-8');
      const spec = JSON.parse(fileContent) as {
        title: unknown;
        description?: string;
        pageVariant?: unknown;
        path?: string;
        elements: AuthoredSpecElementMap;
      };
      pageTitle = typeof spec.title === 'string' ? spec.title : undefined;
      const elements = serializeElementMapForServer(
        spec.elements ?? {},
        componentMetadata,
      );
      const components = authoredElementMapToComponentTree(
        elements,
        componentVersions,
      );
      return {
        uuid: localPage.uuid,
        title: pageTitle ?? localPage.name,
        description: spec.description ?? '',
        pageVariant:
          typeof spec.pageVariant === 'string' && spec.pageVariant !== ''
            ? spec.pageVariant
            : null,
        path: spec.path ?? '',
        components,
        filePath: localPage.path,
      };
    } catch (error) {
      throw withPageTitle(error, pageTitle);
    }
  });

  return {
    valid: results
      .filter((r) => r.success && r.result)
      .map((r) => ({ index: r.index, result: r.result! })),
    failed: results
      .filter((r) => !r.success)
      .map((r) => ({
        index: r.index,
        error: r.error!,
        pageTitle: (r.error as PagePreparationError | undefined)?.pageTitle,
      })),
  };
}

/**
 * Pushes prepared pages to the server, creating new pages or updating existing
 * ones based on UUID matching.
 */
export async function pushPages(
  preparedPages: Array<{ index: number; result: PreparedPage }>,
  remotePageByUuid: Map<string, PageListItem>,
  apiService: Pick<ApiService, 'createPage' | 'updatePage'>,
): Promise<PagePushOperationResult[]> {
  const results = await processInPool(preparedPages, async (entry) => {
    const page = entry.result;
    const remotePage = page.uuid ? remotePageByUuid.get(page.uuid) : undefined;

    if (remotePage) {
      // Keep this guard at the write boundary even though push validates earlier.
      const pathAliasChange = getPathAliasChange(page.path, remotePage.path);
      if (pathAliasChange) {
        throw new Error(formatPagePathAliasChangeError(pathAliasChange));
      }

      await apiService.updatePage(remotePage.id, {
        title: page.title,
        description: page.description,
        pageVariant: page.pageVariant,
        status: remotePage.status,
        path: normalizePathAlias(page.path),
        components: page.components,
      });
      return { title: page.title, operation: 'Updated' as const };
    } else {
      const created = await apiService.createPage({
        title: page.title,
        description: page.description,
        pageVariant: page.pageVariant,
        status: false,
        path: page.path,
        components: page.components,
      });
      // Write the server-assigned UUID back into the local file.
      const fileContent = await fs.readFile(page.filePath, 'utf-8');
      const spec = JSON.parse(fileContent);
      spec.uuid = created.uuid;
      await fs.writeFile(
        page.filePath,
        JSON.stringify(spec, null, 2) + '\n',
        'utf-8',
      );
      return { title: page.title, operation: 'Created' as const };
    }
  });

  return results.map((result) => {
    const preparedPage = preparedPages[result.index];
    return {
      ...result,
      index: preparedPage?.index ?? result.index,
      pageTitle: preparedPage?.result.title,
    };
  });
}

/**
 * Collects push results into Result[] for reporting.
 */
export function collectPageResults(
  pushResults: PagePushOperationResult[],
  failedPreps: PagePreparationFailure[],
  discoveredPages: DiscoveredPage[],
): Result[] {
  const results: Result[] = [];

  for (const result of pushResults) {
    if (result.success && result.result) {
      results.push({
        itemName: result.result.title,
        success: true,
        details: [{ content: result.result.operation }],
      });
    } else {
      const discoveredPage = discoveredPages[result.index];
      results.push({
        itemName: pageResultName(result.pageTitle, discoveredPage, {
          includePath: true,
        }),
        success: false,
        details: [{ content: result.error?.message || 'Unknown error' }],
      });
    }
  }

  for (const failedPrep of failedPreps) {
    const discoveredPage = discoveredPages[failedPrep.index];
    results.push({
      itemName: pageResultName(failedPrep.pageTitle, discoveredPage, {
        includePath: true,
      }),
      success: false,
      details: [
        { content: failedPrep.error?.message || 'Failed to prepare page' },
      ],
    });
  }

  return results;
}

function withPageTitle(error: unknown, pageTitle: string | undefined): Error {
  const normalizedError =
    error instanceof Error ? error : new Error(String(error));
  (normalizedError as PagePreparationError).pageTitle = pageTitle;
  return normalizedError;
}
