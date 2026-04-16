import fs from 'fs/promises';
import { loadComponentsMetadata } from '@drupal-canvas/discovery';

import { authoredSpecToComponentTree } from './pages';
import {
  collectUnreconciledMediaProps,
  serializeElementMapForServer,
} from './prop-transforms';
import { processInPool } from './request-pool';

import type { DiscoveredPage, DiscoveryResult } from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { ApiService } from '../services/api';
import type { Page, PageListItem } from '../types/Page';
import type { Result } from '../types/Result';

export interface PendingMediaReconciliation {
  index: number;
  title: string;
  filePath: string;
  elementId: string;
  propName: string;
  src: string;
  mediaType: string;
}

export interface PagePushResult {
  title: string;
  operation: 'Created' | 'Updated';
}

export interface PreparedPage {
  uuid: string | null;
  title: string;
  components: Page['components'];
  filePath: string;
  pendingMediaReconciliations: Array<
    Omit<PendingMediaReconciliation, 'index' | 'title' | 'filePath'>
  >;
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
  failed: Array<{ index: number; error: Error }>;
  pendingMediaReconciliations: PendingMediaReconciliation[];
}> {
  const componentMetadata = await loadComponentsMetadata(discoveryResult);

  const results = await processInPool(discoveredPages, async (localPage) => {
    const fileContent = await fs.readFile(localPage.path, 'utf-8');
    const spec = JSON.parse(fileContent) as {
      title: string;
      elements: AuthoredSpecElementMap;
    };
    const pendingMediaReconciliations = collectUnreconciledMediaProps(
      spec.elements ?? {},
      componentMetadata,
    );
    const elements = serializeElementMapForServer(
      spec.elements ?? {},
      componentMetadata,
    );
    const components = authoredSpecToComponentTree(elements, componentVersions);
    return {
      uuid: localPage.uuid,
      title: spec.title,
      components,
      filePath: localPage.path,
      pendingMediaReconciliations,
    };
  });

  return {
    valid: results
      .filter((r) => r.success && r.result)
      .map((r) => ({ index: r.index, result: r.result! })),
    failed: results
      .filter((r) => !r.success)
      .map((r) => ({ index: r.index, error: r.error! })),
    pendingMediaReconciliations: results
      .filter((r) => r.success && r.result)
      .flatMap((r) =>
        r.result!.pendingMediaReconciliations.map((entry) => ({
          index: r.index,
          title: r.result!.title,
          filePath: r.result!.filePath,
          ...entry,
        })),
      ),
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
): Promise<
  Array<{
    success: boolean;
    result?: PagePushResult;
    error?: Error;
    index: number;
  }>
> {
  return processInPool(preparedPages, async (entry) => {
    const page = entry.result;
    const remotePage = page.uuid ? remotePageByUuid.get(page.uuid) : undefined;

    if (remotePage) {
      await apiService.updatePage(remotePage.id, {
        title: page.title,
        status: remotePage.status,
        path: remotePage.path,
        components: page.components,
      });
      return { title: page.title, operation: 'Updated' as const };
    } else {
      const created = await apiService.createPage({
        title: page.title,
        status: false,
        path: '',
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
}

/**
 * Collects push results into Result[] for reporting.
 */
export function collectPageResults(
  pushResults: Array<{
    success: boolean;
    result?: PagePushResult;
    error?: Error;
    index: number;
  }>,
  failedPreps: Array<{ index: number; error: Error }>,
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
      const slug = discoveredPages[result.index]?.slug || 'unknown';
      results.push({
        itemName: slug,
        success: false,
        details: [{ content: result.error?.message || 'Unknown error' }],
      });
    }
  }

  for (const failedPrep of failedPreps) {
    const slug = discoveredPages[failedPrep.index]?.slug || 'unknown';
    results.push({
      itemName: slug,
      success: false,
      details: [
        { content: failedPrep.error?.message || 'Failed to prepare page' },
      ],
    });
  }

  return results;
}
