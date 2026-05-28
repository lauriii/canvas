import fs from 'fs/promises';
import chalk from 'chalk';
import { loadComponentsMetadata } from '@drupal-canvas/discovery';

import { authoredElementMapToComponentTree } from './authored-elements';
import { stripNullableKeysForConfigComponentTree } from './component-tree-payload';
import { serializeElementMapForServer } from './prop-transforms';
import { processInPool } from './request-pool';
import { isRecord } from './utils';

import type {
  DiscoveredContentTemplate,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
import type {
  AuthoredSpecElement,
  AuthoredSpecElementMap,
  CanvasComponentTree,
} from 'drupal-canvas/json-render-utils';
import type { ApiService } from '../services/api';
import type { ContentTemplateListItem } from '../types/ContentTemplate';
import type { Result } from '../types/Result';

export interface ContentTemplatePushResult {
  label: string;
  id: string;
  operation: 'Created' | 'Updated';
}

export interface PreparedContentTemplate {
  id: string;
  label: string;
  entityTypeId: string;
  bundle: string;
  viewMode: string;
  components: CanvasComponentTree;
  filePath: string;
}

function deriveId(spec: {
  entityType: string;
  bundle: string;
  viewMode: string;
}): string {
  return `${spec.entityType}.${spec.bundle}.${spec.viewMode}`;
}

/**
 * Walks an element map and returns the prop paths (as `elementKey.propKey`)
 * containing legacy `{ "$state": "..." }` pointers. Authored content
 * templates now store bindings as `{ sourceType, expression, ... }` prop
 * sources directly; `$state` pointers from older drafts no longer round-trip.
 */
function findLegacyStatePointers(elements: AuthoredSpecElementMap): string[] {
  const paths: string[] = [];
  for (const [elementKey, element] of Object.entries(elements)) {
    const props = (element as AuthoredSpecElement).props;
    if (!isRecord(props)) continue;
    for (const [propKey, value] of Object.entries(props)) {
      if (
        isRecord(value) &&
        typeof value.$state === 'string' &&
        Object.keys(value).length === 1
      ) {
        paths.push(`${elementKey}.${propKey}`);
      }
    }
  }
  return paths;
}

/**
 * Reads discovered content templates, validates them, and converts the
 * authored element map into the server's component_tree wire format.
 */
export async function prepareContentTemplates(
  discovered: DiscoveredContentTemplate[],
  componentVersions: Map<string, string>,
  discoveryResult: DiscoveryResult,
): Promise<{
  valid: Array<{ index: number; result: PreparedContentTemplate }>;
  failed: Array<{ index: number; error: Error }>;
}> {
  const componentMetadata = await loadComponentsMetadata(discoveryResult);

  const results = await processInPool(discovered, async (localTemplate) => {
    const fileContent = await fs.readFile(localTemplate.path, 'utf-8');
    const spec = JSON.parse(fileContent) as {
      label: string;
      entityType: string;
      bundle: string;
      viewMode: string;
      elements: AuthoredSpecElementMap;
    };

    if (!spec.entityType || !spec.bundle || !spec.viewMode) {
      throw new Error(
        `Content template file is missing required entity-type metadata: ${localTemplate.path}`,
      );
    }
    if (!spec.label) {
      throw new Error(
        `Content template file is missing a "label": ${localTemplate.path}`,
      );
    }

    const legacyStatePaths = findLegacyStatePointers(spec.elements ?? {});
    if (legacyStatePaths.length > 0) {
      throw new Error(
        `Cannot push content template "${spec.label}" (${localTemplate.path}): legacy "$state" pointers are no longer supported in authored files. Run \`canvas pull\` to regenerate, or replace each pointer with a prop-source object (e.g. {"sourceType":"entity-field","expression":"…"}). Affected props: ${legacyStatePaths.join(', ')}.`,
      );
    }

    const serializedElements = serializeElementMapForServer(
      spec.elements ?? {},
      componentMetadata,
    );
    const tree = authoredElementMapToComponentTree(
      serializedElements,
      componentVersions,
    );

    return {
      id: deriveId(spec),
      label: spec.label,
      entityTypeId: spec.entityType,
      bundle: spec.bundle,
      viewMode: spec.viewMode,
      components: tree,
      filePath: localTemplate.path,
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
 * Pushes prepared content templates to the server, creating or updating based
 * on matching config entity id.
 */
export async function pushContentTemplates(
  prepared: Array<{ index: number; result: PreparedContentTemplate }>,
  remoteById: Map<string, ContentTemplateListItem>,
  apiService: Pick<
    ApiService,
    'createContentTemplate' | 'updateContentTemplate'
  >,
): Promise<
  Array<{
    success: boolean;
    result?: ContentTemplatePushResult;
    error?: Error;
    index: number;
  }>
> {
  return processInPool(prepared, async (entry) => {
    const template = entry.result;
    const remote = remoteById.get(template.id);

    const component_tree = stripNullableKeysForConfigComponentTree(
      template.components,
    );

    if (remote) {
      await apiService.updateContentTemplate(template.id, {
        status: true,
        component_tree,
      });
      return {
        label: template.label,
        id: template.id,
        operation: 'Updated' as const,
      };
    }

    await apiService.createContentTemplate({
      label: template.label,
      entityType: template.entityTypeId,
      bundle: template.bundle,
      viewMode: template.viewMode,
      status: true,
      component_tree,
    });
    return {
      label: template.label,
      id: template.id,
      operation: 'Created' as const,
    };
  });
}

/**
 * Collects push results into `Result[]` for reporting.
 */
export function collectContentTemplateResults(
  pushResults: Array<{
    success: boolean;
    result?: ContentTemplatePushResult;
    error?: Error;
    index: number;
  }>,
  failedPreps: Array<{ index: number; error: Error }>,
  discovered: DiscoveredContentTemplate[],
): Result[] {
  const results: Result[] = [];

  for (const result of pushResults) {
    if (result.success && result.result) {
      results.push({
        itemName: result.result.label,
        success: true,
        details: [
          {
            content:
              result.result.operation === 'Updated'
                ? chalk.cyan(result.result.operation)
                : result.result.operation,
          },
        ],
      });
    } else {
      const fallback =
        discovered[result.index]?.label ??
        discovered[result.index]?.slug ??
        'unknown';
      results.push({
        itemName: fallback,
        success: false,
        details: [{ content: result.error?.message || 'Unknown error' }],
      });
    }
  }

  for (const failedPrep of failedPreps) {
    const fallback =
      discovered[failedPrep.index]?.label ??
      discovered[failedPrep.index]?.slug ??
      'unknown';
    results.push({
      itemName: fallback,
      success: false,
      details: [
        {
          content:
            failedPrep.error?.message || 'Failed to prepare content template',
        },
      ],
    });
  }

  return results;
}
