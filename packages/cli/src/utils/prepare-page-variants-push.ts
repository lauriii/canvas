import fs from 'fs/promises';
import { loadComponentsMetadata } from '@drupal-canvas/discovery';

import { authoredElementMapToComponentTree } from './authored-elements';
import { stripNullableKeysForConfigComponentTree } from './component-tree-payload';
import { serializeElementMapForServer } from './prop-transforms';
import { processInPool } from './request-pool';

import type {
  DiscoveredPageTemplate,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { ApiService } from '../services/api';
import type { PageVariant } from '../types/PageVariant';
import type { Result } from '../types/Result';

export interface PageVariantPushResult {
  id: string;
  operation: 'Created' | 'Updated' | 'Deleted' | 'Set as default' | 'Skipped';
  detail?: string;
}

export interface PreparedPageVariant {
  id: string;
  label: string;
  description: string | null;
  status: boolean;
  default: boolean;
  components: PageVariant['component_tree'];
  filePath: string;
}

interface AuthoredPageTemplateFile {
  label?: string;
  description?: string;
  status?: boolean;
  default?: boolean;
  elements?: AuthoredSpecElementMap;
}

/**
 * Reads each page template file and converts the authored elements to a
 * component tree. The page variant's machine name is sourced from the
 * filename.
 */
export async function preparePageVariants(
  discoveredPageTemplates: DiscoveredPageTemplate[],
  componentVersions: Map<string, string>,
  discoveryResult: DiscoveryResult,
): Promise<{
  valid: Array<{ index: number; result: PreparedPageVariant }>;
  failed: Array<{ index: number; error: Error }>;
}> {
  const componentMetadata = await loadComponentsMetadata(discoveryResult);

  const results = await processInPool(
    discoveredPageTemplates,
    async (discovered) => {
      const fileContent = await fs.readFile(discovered.path, 'utf-8');
      const spec = JSON.parse(fileContent) as AuthoredPageTemplateFile;

      if (!spec.label) {
        throw new Error(
          `Page template file is missing a "label": ${discovered.path}`,
        );
      }

      const elements = serializeElementMapForServer(
        spec.elements ?? {},
        componentMetadata,
      );
      const components = authoredElementMapToComponentTree(
        elements,
        componentVersions,
      );

      return {
        id: discovered.id,
        label: spec.label,
        description: spec.description || null,
        status: spec.status ?? true,
        default: spec.default === true,
        components,
        filePath: discovered.path,
      };
    },
  );

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
 * Sync prepared page variants with the server: create new ones, update
 * existing ones, delete remote variants that are absent locally, and align
 * the site default with the (at most one) authored `default: true` file.
 *
 * When the current site default is absent locally, a replacement becomes the
 * default before the previous variant is deleted. Without a replacement, the
 * current default is kept.
 */
export async function pushPageVariants(
  preparedPageVariants: Array<{ index: number; result: PreparedPageVariant }>,
  remoteIds: Set<string>,
  apiService: Pick<
    ApiService,
    | 'createPageVariant'
    | 'updatePageVariant'
    | 'deletePageVariant'
    | 'setDefaultPageVariant'
  >,
  options: {
    remoteIdsToDelete?: string[];
    currentDefault?: string | null;
  } = {},
): Promise<
  Array<{
    success: boolean;
    result?: PageVariantPushResult;
    error?: Error;
    index: number;
  }>
> {
  const remoteIdsToDelete = options.remoteIdsToDelete ?? [];
  const currentDefault = options.currentDefault ?? null;

  const authoredDefaults = preparedPageVariants.filter(
    (entry) => entry.result.default,
  );
  if (authoredDefaults.length > 1) {
    const ids = authoredDefaults.map((entry) => entry.result.id).join(', ');
    throw new Error(
      `Only one page template may set "default": true, found ${authoredDefaults.length} (${ids}).`,
    );
  }

  const upsertResults = await processInPool(
    preparedPageVariants,
    async (entry) => {
      const variant = entry.result;

      const component_tree = stripNullableKeysForConfigComponentTree(
        variant.components,
      );

      if (remoteIds.has(variant.id)) {
        await apiService.updatePageVariant(variant.id, {
          label: variant.label,
          description: variant.description,
          status: variant.status,
          component_tree,
        });
        return { id: variant.id, operation: 'Updated' as const };
      }

      await apiService.createPageVariant({
        id: variant.id,
        label: variant.label,
        description: variant.description,
        status: variant.status,
        component_tree,
      });
      return { id: variant.id, operation: 'Created' as const };
    },
  );

  const results: Array<{
    success: boolean;
    result?: PageVariantPushResult;
    error?: Error;
    index: number;
  }> = [...upsertResults];

  // Align the site default after the upserts so a newly created variant can
  // become the default in the same push. No authored `default: true` leaves
  // the server's default untouched.
  const desiredDefaultEntry = authoredDefaults[0];
  const desiredDefault = desiredDefaultEntry?.result.id ?? null;
  const desiredDefaultUpsertSucceeded =
    desiredDefaultEntry !== undefined &&
    upsertResults.find(
      (result) =>
        result.index === preparedPageVariants.indexOf(desiredDefaultEntry),
    )?.success === true;
  let defaultWasChanged = false;
  if (
    desiredDefault !== null &&
    desiredDefault !== currentDefault &&
    desiredDefaultUpsertSucceeded
  ) {
    try {
      await apiService.setDefaultPageVariant(desiredDefault);
      defaultWasChanged = true;
      results.push({
        success: true,
        result: { id: desiredDefault, operation: 'Set as default' },
        index: results.length,
      });
    } catch (error) {
      results.push({
        success: false,
        result: { id: desiredDefault, operation: 'Set as default' },
        error: error instanceof Error ? error : new Error(String(error)),
        index: results.length,
      });
    }
  }

  const idsToDelete = remoteIdsToDelete.filter(
    (id) => id !== currentDefault || defaultWasChanged,
  );
  const deleteResults = await processInPool(idsToDelete, async (id) => {
    await apiService.deletePageVariant(id);
    return { id, operation: 'Deleted' as const };
  });
  results.push(
    ...deleteResults.map((result) => ({
      ...result,
      index: results.length + result.index,
      result:
        result.result ??
        ({
          id: idsToDelete[result.index],
          operation: 'Deleted' as const,
        } satisfies PageVariantPushResult),
    })),
  );

  if (
    currentDefault !== null &&
    remoteIdsToDelete.includes(currentDefault) &&
    !defaultWasChanged
  ) {
    results.push({
      success: true,
      result: {
        id: currentDefault,
        operation: 'Skipped',
        detail:
          'Kept the current site default because no replacement became the default.',
      },
      index: results.length,
    });
  }

  return results;
}

export function collectPageVariantResults(
  pushResults: Array<{
    success: boolean;
    result?: PageVariantPushResult;
    error?: Error;
    index: number;
  }>,
  failedPreps: Array<{ index: number; error: Error }>,
  discoveredPageTemplates: DiscoveredPageTemplate[],
): Result[] {
  const results: Result[] = [];

  for (const result of pushResults) {
    if (result.success && result.result) {
      results.push({
        itemName: result.result.id,
        success: true,
        details: [{ content: result.result.detail ?? result.result.operation }],
      });
    } else {
      const name =
        result.result?.id ??
        discoveredPageTemplates[result.index]?.id ??
        'unknown';
      results.push({
        itemName: name,
        success: false,
        details: [{ content: result.error?.message || 'Unknown error' }],
      });
    }
  }

  for (const failedPrep of failedPreps) {
    const id = discoveredPageTemplates[failedPrep.index]?.id || 'unknown';
    results.push({
      itemName: id,
      success: false,
      details: [
        {
          content:
            failedPrep.error?.message || 'Failed to prepare page template',
        },
      ],
    });
  }

  return results;
}
