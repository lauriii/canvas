import fs from 'fs/promises';
import { loadComponentsMetadata } from '@drupal-canvas/discovery';

import { authoredElementMapToComponentTree } from './authored-elements';
import { stripNullableKeysForConfigComponentTree } from './component-tree-payload';
import { serializeElementMapForServer } from './prop-transforms';
import { processInPool } from './request-pool';

import type {
  DiscoveredRegion,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { ApiService } from '../services/api';
import type { Region } from '../types/Region';
import type { Result } from '../types/Result';

export interface RegionPushResult {
  region: string;
  operation: 'Created' | 'Updated' | 'Deleted';
}

export interface PreparedRegion {
  region: string;
  status: boolean;
  components: Region['component_tree'];
  filePath: string;
}

interface AuthoredRegionFile {
  status?: boolean;
  elements?: AuthoredSpecElementMap;
}

/**
 * Reads each region file and converts the authored elements to a component
 * tree. The region's machine name is sourced from the filename; the theme is
 * implicit and resolved by the server from the site's default theme.
 */
export async function prepareRegions(
  discoveredRegions: DiscoveredRegion[],
  componentVersions: Map<string, string>,
  discoveryResult: DiscoveryResult,
): Promise<{
  valid: Array<{ index: number; result: PreparedRegion }>;
  failed: Array<{ index: number; error: Error }>;
}> {
  const componentMetadata = await loadComponentsMetadata(discoveryResult);

  const results = await processInPool(discoveredRegions, async (discovered) => {
    const fileContent = await fs.readFile(discovered.path, 'utf-8');
    const spec = JSON.parse(fileContent) as AuthoredRegionFile;

    const elements = serializeElementMapForServer(
      spec.elements ?? {},
      componentMetadata,
    );
    const components = authoredElementMapToComponentTree(
      elements,
      componentVersions,
    );

    return {
      region: discovered.region,
      status: spec.status ?? true,
      components,
      filePath: discovered.path,
    };
  });

  return {
    valid: results
      .filter((r) => r.success && r.result)
      .map((r) => ({ index: r.index, result: r.result! })),
    failed: results
      .filter((r) => !r.success)
      .map((r) => ({ index: r.index, error: r.error! })),
  };
}

/**
 * Sync prepared regions with the server: create new ones, update existing
 * ones, and delete remote regions that are absent locally.
 */
export async function pushRegions(
  preparedRegions: Array<{ index: number; result: PreparedRegion }>,
  remoteIdsByName: Map<string, string>,
  apiService: Pick<
    ApiService,
    'createRegion' | 'updateRegion' | 'deleteRegion'
  >,
  remoteNamesToDelete: string[] = [],
): Promise<
  Array<{
    success: boolean;
    result?: RegionPushResult;
    error?: Error;
    index: number;
  }>
> {
  const upsertResults = await processInPool(preparedRegions, async (entry) => {
    const region = entry.result;
    const remoteId = remoteIdsByName.get(region.region);

    const component_tree = stripNullableKeysForConfigComponentTree(
      region.components,
    );

    if (remoteId) {
      await apiService.updateRegion(remoteId, {
        status: region.status,
        component_tree,
      });
      return { region: region.region, operation: 'Updated' as const };
    }

    // Omit `theme`: the server fills it in from the site's default theme.
    await apiService.createRegion({
      region: region.region,
      status: region.status,
      component_tree,
    });
    return { region: region.region, operation: 'Created' as const };
  });

  if (remoteNamesToDelete.length === 0) {
    return upsertResults;
  }

  const deleteResults = await processInPool(
    remoteNamesToDelete,
    async (name) => {
      const fullId = remoteIdsByName.get(name);
      if (!fullId) {
        throw new Error(`Unknown remote region: ${name}`);
      }
      await apiService.deleteRegion(fullId);
      return { region: name, operation: 'Deleted' as const };
    },
  );

  return [
    ...upsertResults,
    ...deleteResults.map((r) => ({
      ...r,
      result:
        r.result ??
        ({
          region: remoteNamesToDelete[r.index],
          operation: 'Deleted' as const,
        } satisfies RegionPushResult),
    })),
  ];
}

export function collectRegionResults(
  pushResults: Array<{
    success: boolean;
    result?: RegionPushResult;
    error?: Error;
    index: number;
  }>,
  failedPreps: Array<{ index: number; error: Error }>,
  discoveredRegions: DiscoveredRegion[],
): Result[] {
  const results: Result[] = [];

  for (const result of pushResults) {
    if (result.success && result.result) {
      results.push({
        itemName: result.result.region,
        success: true,
        details: [{ content: result.result.operation }],
      });
    } else {
      const name =
        result.result?.region ??
        discoveredRegions[result.index]?.region ??
        'unknown';
      results.push({
        itemName: name,
        success: false,
        details: [{ content: result.error?.message || 'Unknown error' }],
      });
    }
  }

  for (const failedPrep of failedPreps) {
    const id = discoveredRegions[failedPrep.index]?.region || 'unknown';
    results.push({
      itemName: id,
      success: false,
      details: [
        {
          content: failedPrep.error?.message || 'Failed to prepare region',
        },
      ],
    });
  }

  return results;
}
