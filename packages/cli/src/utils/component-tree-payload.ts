import type { CanvasComponentTree } from 'drupal-canvas/json-render-utils';

/**
 * Wire-format component tree node for POSTing/PATCHing config entities that
 * embed a Canvas component tree (PageRegion, ContentTemplate, etc.).
 *
 * These config entities' `component_tree` schemas reject null values for
 * `parent_uuid`, `slot`, and `label` (unlike the page content entity, which
 * tolerates nulls). Keys are therefore optional rather than nullable.
 */
export interface ConfigComponentTreeNodePayload {
  uuid: string;
  component_id: string;
  component_version: string;
  inputs: Record<string, unknown>;
  parent_uuid?: string;
  slot?: string;
  label?: string;
}

export type ConfigComponentTreePayload = ConfigComponentTreeNodePayload[];

/**
 * Strips null/undefined `parent_uuid`, `slot`, and `label` from each node so
 * the server-side config schema validation accepts the payload. Used by the
 * region and content template push paths.
 */
export function stripNullableKeysForConfigComponentTree(
  components: CanvasComponentTree,
): ConfigComponentTreePayload {
  return components.map((node) => {
    const result: ConfigComponentTreeNodePayload = {
      uuid: node.uuid,
      component_id: node.component_id,
      component_version: node.component_version ?? '',
      inputs: node.inputs,
    };
    if (node.parent_uuid !== null && node.parent_uuid !== undefined) {
      result.parent_uuid = node.parent_uuid;
    }
    if (node.slot !== null && node.slot !== undefined) {
      result.slot = node.slot;
    }
    if (node.label !== null && node.label !== undefined && node.label !== '') {
      result.label = node.label;
    }
    return result;
  });
}
