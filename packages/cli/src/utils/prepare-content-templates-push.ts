import fs from 'fs/promises';
import chalk from 'chalk';
import { loadComponentsMetadata } from '@drupal-canvas/discovery';

import {
  authoredElementMapToComponentTree,
  buildElementKeyToUuidMap,
} from './authored-elements';
import { stripNullableKeysForConfigComponentTree } from './component-tree-payload';
import { contentTemplateResultName } from './content-template-result-name';
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
import type {
  ContentTemplateListItem,
  ExposedSlots,
} from '../types/ContentTemplate';
import type { Result } from '../types/Result';

export interface ContentTemplatePushResult {
  label: string;
  id: string;
  operation: 'Created' | 'Updated';
}

export interface ContentTemplatePushOperationResult {
  success: boolean;
  result?: ContentTemplatePushResult;
  error?: Error;
  index: number;
}

export interface PreparedContentTemplate {
  id: string;
  label: string;
  entityTypeId: string;
  bundle: string;
  viewMode: string;
  pageVariant: string | null;
  components: CanvasComponentTree;
  exposedSlots?: ExposedSlots;
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
      pageVariant?: unknown;
      elements: AuthoredSpecElementMap;
      exposedSlots?: ExposedSlots;
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
        `Legacy "$state" pointers are no longer supported in authored files. Run \`canvas pull\` to regenerate, or replace each pointer with a prop-source object (e.g. {"sourceType":"entity-field","expression":"…"}). Affected props: ${legacyStatePaths.join(', ')}.`,
      );
    }

    const serializedElements = serializeElementMapForServer(
      spec.elements ?? {},
      componentMetadata,
    );
    // Authored element keys may be friendly aliases; the tree builder mints
    // real UUIDs for those. Build the key→UUID map here and share it, so
    // exposed slots' component_uuid references translate to the same UUIDs
    // the pushed tree carries (a valid-UUID key maps to itself).
    const keyToUuid = buildElementKeyToUuidMap(Object.keys(serializedElements));
    const tree = authoredElementMapToComponentTree(
      serializedElements,
      componentVersions,
      keyToUuid,
    );
    const exposedSlots = spec.exposedSlots
      ? Object.fromEntries(
          Object.entries(spec.exposedSlots).map(([alias, slot]) => [
            alias,
            {
              ...slot,
              component_uuid:
                keyToUuid.get(slot.component_uuid) ?? slot.component_uuid,
            },
          ]),
        )
      : undefined;

    return {
      id: deriveId(spec),
      label: spec.label,
      entityTypeId: spec.entityType,
      bundle: spec.bundle,
      // Anything that is not a non-empty string means "no selection" (the
      // site default).
      pageVariant:
        typeof spec.pageVariant === 'string' && spec.pageVariant !== ''
          ? spec.pageVariant
          : null,
      viewMode: spec.viewMode,
      components: tree,
      exposedSlots,
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
    'createContentTemplate' | 'updateContentTemplate' | 'createSlotField'
  >,
): Promise<ContentTemplatePushOperationResult[]> {
  const results = await processInPool(prepared, async (entry) => {
    const template = entry.result;
    const remote = remoteById.get(template.id);

    const component_tree = stripNullableKeysForConfigComponentTree(
      template.components,
    );
    // A template referencing exposed slots only validates when each slot's
    // backing `component_tree` field exists on the target bundle. The
    // slot-field endpoint requires an existing template, so a fresh-site
    // create sequences: create without slots, provision the fields, then
    // update with the slots. Provisioning is create-if-missing (409 is fine),
    // so the update path runs it too: the authored file may reference slots
    // the target site has never seen.
    const provisionSlotFields = async () => {
      for (const [fieldName, slot] of Object.entries(
        template.exposedSlots ?? {},
      )) {
        // The slot-field endpoint only creates canvas_slot_-prefixed fields
        // (@see ApiContentTemplateSlotFieldController::FIELD_NAME_PREFIX); a
        // reused pre-existing component_tree field with another name cannot
        // be provisioned here and must already exist on the target — if it
        // does not, the template update fails its ValidExposedSlot check.
        if (!fieldName.startsWith('canvas_slot_')) {
          continue;
        }
        await apiService.createSlotField(template.id, fieldName, slot.label);
      }
    };

    if (remote) {
      await provisionSlotFields();
      // The update always carries exposed_slots: pull represents a slot-free
      // template by omitting the property from the authored file, so an
      // update must send the empty map to detach slots still present on the
      // target (the backing fields and their content are retained).
      await apiService.updateContentTemplate(template.id, {
        status: true,
        pageVariant: template.pageVariant,
        component_tree,
        exposed_slots: template.exposedSlots ?? {},
      });
      return {
        label: template.label,
        id: template.id,
        operation: 'Updated' as const,
      };
    }

    await apiService.createContentTemplate({
      entityType: template.entityTypeId,
      bundle: template.bundle,
      viewMode: template.viewMode,
      pageVariant: template.pageVariant,
      status: true,
      component_tree,
    });
    if (template.exposedSlots) {
      await provisionSlotFields();
      // The server payload key is snake_case `exposed_slots`.
      await apiService.updateContentTemplate(template.id, {
        status: true,
        exposed_slots: template.exposedSlots,
      });
    }
    return {
      label: template.label,
      id: template.id,
      operation: 'Created' as const,
    };
  });

  return results.map((result) => {
    const preparedTemplate = prepared[result.index];
    return {
      ...result,
      index: preparedTemplate?.index ?? result.index,
    };
  });
}

/**
 * Collects push results into `Result[]` for reporting.
 */
export function collectContentTemplateResults(
  pushResults: ContentTemplatePushOperationResult[],
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
      const discoveredTemplate = discovered[result.index];
      results.push({
        itemName: contentTemplateResultName(undefined, discoveredTemplate, {
          includeFileName: true,
        }),
        success: false,
        details: [{ content: result.error?.message || 'Unknown error' }],
      });
    }
  }

  for (const failedPrep of failedPreps) {
    const discoveredTemplate = discovered[failedPrep.index];
    results.push({
      itemName: contentTemplateResultName(undefined, discoveredTemplate, {
        includeFileName: true,
      }),
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
