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
  BrandKitColorEntry,
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
  ExposedSlot,
  ExposedSlots,
} from '../types/ContentTemplate';
import type { Result } from '../types/Result';

/**
 * The machine-name prefix of slot fields the slot-field endpoint can create.
 *
 * @see \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::FIELD_NAME_PREFIX
 */
const SLOT_FIELD_PREFIX = 'canvas_slot_';

export interface ContentTemplatePushResult {
  label: string;
  id: string;
  operation: 'Created' | 'Updated';
  // Exposed slots on the target that the push detached, by field name.
  detachedSlots?: string[];
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
 * Asserts that an authored exposed slot has all its keys as non-empty strings.
 */
function assertExposedSlot(
  alias: string,
  slot: unknown,
): asserts slot is ExposedSlot {
  for (const key of ['component_uuid', 'slot_name', 'label'] as const) {
    const value = isRecord(slot) ? slot[key] : undefined;
    if (typeof value !== 'string' || value === '') {
      throw new Error(`Exposed slot "${alias}" is missing a valid "${key}".`);
    }
  }
}

/**
 * Reads discovered content templates, validates them, and converts the
 * authored element map into the server's component_tree wire format.
 */
export async function prepareContentTemplates(
  discovered: DiscoveredContentTemplate[],
  componentVersions: Map<string, string>,
  discoveryResult: DiscoveryResult,
  remoteBrandKitColors: BrandKitColorEntry[] = [],
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
      remoteBrandKitColors,
    );
    // Authored element keys may be names instead of UUIDs. Share one
    // key-to-UUID map, so exposed slots point at the same UUIDs as the tree.
    const keyToUuid = buildElementKeyToUuidMap(Object.keys(serializedElements));
    const tree = authoredElementMapToComponentTree(
      serializedElements,
      componentVersions,
      keyToUuid,
    );
    // Validate exposed slots here, so errors name the slot.
    const treeUuids = new Set(tree.map((component) => component.uuid));
    const exposedSlots = spec.exposedSlots
      ? Object.fromEntries(
          Object.entries(spec.exposedSlots).map(([alias, slot]) => {
            assertExposedSlot(alias, slot);
            const componentUuid =
              keyToUuid.get(slot.component_uuid) ?? slot.component_uuid;
            if (!treeUuids.has(componentUuid)) {
              throw new Error(
                `Exposed slot "${alias}" references unknown element "${slot.component_uuid}".`,
              );
            }
            return [alias, { ...slot, component_uuid: componentUuid }];
          }),
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
  includePageVariant: boolean = true,
): Promise<ContentTemplatePushOperationResult[]> {
  const results = await processInPool(prepared, async (entry) => {
    const template = entry.result;
    const remote = remoteById.get(template.id);

    const component_tree = stripNullableKeysForConfigComponentTree(
      template.components,
    );
    // Exposed slots only validate once their fields exist, and creating a
    // field requires the template. So a new template is pushed without slots
    // first, then its fields are created, then the slots are added.
    const provisionSlotFields = async () => {
      for (const [fieldName, slot] of Object.entries(
        template.exposedSlots ?? {},
      )) {
        // Only prefixed fields can be created. A reused field with another
        // name must already exist on the target site.
        if (!fieldName.startsWith(SLOT_FIELD_PREFIX)) {
          continue;
        }
        await apiService.createSlotField(template.id, fieldName, slot.label);
      }
    };

    if (remote) {
      await provisionSlotFields();
      // Always send exposed_slots: a template without slots in the authored
      // file detaches the slots on the target. Their fields and content stay.
      const exposedSlots = template.exposedSlots ?? {};
      await apiService.updateContentTemplate(template.id, {
        status: true,
        ...(includePageVariant ? { pageVariant: template.pageVariant } : {}),
        component_tree,
        exposed_slots: exposedSlots,
      });
      const detachedSlots = Object.keys(remote.exposed_slots ?? {}).filter(
        (fieldName) => !(fieldName in exposedSlots),
      );
      return {
        label: template.label,
        id: template.id,
        operation: 'Updated' as const,
        ...(detachedSlots.length > 0 ? { detachedSlots } : {}),
      };
    }

    await apiService.createContentTemplate({
      entityType: template.entityTypeId,
      bundle: template.bundle,
      viewMode: template.viewMode,
      ...(includePageVariant ? { pageVariant: template.pageVariant } : {}),
      status: true,
      component_tree,
    });
    if (template.exposedSlots) {
      await provisionSlotFields();
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
          ...(result.result.detachedSlots
            ? [
                {
                  content: chalk.yellow(
                    `Detached exposed slots: ${result.result.detachedSlots.join(', ')}. Their fields and content are kept.`,
                  ),
                },
              ]
            : []),
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
