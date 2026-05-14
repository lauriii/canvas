import {
  buildChildToParentMap,
  buildElementKeyToUuidMap,
} from './authored-element-utils';
import { isRecord } from './utils';

import type {
  AuthoredSpecElement,
  AuthoredSpecElementMap,
  CanvasComponentTree,
} from 'drupal-canvas/json-render-utils';
import type { ContentTemplate } from '../types/ContentTemplate';

/**
 * Returns the prefix (e.g. "static", "entity-field", "adapter") of a server
 * prop-source `sourceType`. The wire format uses either a bare prefix
 * ("entity-field") or a colon-separated form ("static:field_item:string",
 * "adapter:image_apply_style").
 *
 * @see \Drupal\canvas\PropSource\PropSource::parse()
 */
function sourceTypePrefix(value: unknown): string | null {
  if (!isRecord(value) || typeof value.sourceType !== 'string') {
    return null;
  }
  const colon = value.sourceType.indexOf(':');
  return colon === -1 ? value.sourceType : value.sourceType.slice(0, colon);
}

/**
 * Convert a single server prop value into the authored format.
 *
 * - Static prop sources (`{sourceType: "static:...", value: X}`) unwrap to
 *   their inner literal — in authored files, plain values without a
 *   `sourceType` key are the canonical form for static inputs.
 * - The deprecated `dynamic` alias is normalized to `entity-field` so pulled
 *   files always use the canonical sourceType name.
 * - Every other prop source (entity-field, host-entity-url, adapter,
 *   default-relative-url) passes through verbatim.
 * - Plain literals pass through unchanged.
 */
export function serverPropToAuthored(value: unknown): unknown {
  if (
    sourceTypePrefix(value) === 'static' &&
    isRecord(value) &&
    'value' in value
  ) {
    return value.value;
  }
  if (isRecord(value) && value.sourceType === 'dynamic') {
    return { ...value, sourceType: 'entity-field' };
  }
  return value;
}

/**
 * Convert a single authored prop value back into the server's component_tree
 * format. The server wraps plain literals as static at resolve time, and
 * prop-source objects (`{sourceType, ...}`) are sent verbatim, so this is a
 * pass-through.
 */
export function authoredPropToServer(value: unknown): unknown {
  return value;
}

function translateInputsFromServer(
  inputs: Record<string, unknown>,
): Record<string, unknown> {
  const result: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(inputs)) {
    result[key] = serverPropToAuthored(value);
  }
  return result;
}

function translateInputsToServer(
  inputs: Record<string, unknown>,
): Record<string, unknown> {
  const result: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(inputs)) {
    result[key] = authoredPropToServer(value);
  }
  return result;
}

/**
 * Convert a content template's server `component_tree` into an authored
 * element map.
 */
export function componentTreeToAuthoredElements(
  tree: CanvasComponentTree,
): AuthoredSpecElementMap {
  const elements: AuthoredSpecElementMap = {};

  // Build a reverse lookup: parent uuid → { [slot]: [childUuids] }
  const parentToSlots = new Map<string, Record<string, string[]>>();
  for (const node of tree) {
    if (!node.parent_uuid || !node.slot) {
      continue;
    }
    const slots = parentToSlots.get(node.parent_uuid) ?? {};
    const slotChildren = slots[node.slot] ?? [];
    slotChildren.push(node.uuid);
    slots[node.slot] = slotChildren;
    parentToSlots.set(node.parent_uuid, slots);
  }

  for (const node of tree) {
    const rawInputs: Record<string, unknown> =
      typeof node.inputs === 'string'
        ? safeParseInputs(node.inputs)
        : isRecord(node.inputs)
          ? (node.inputs as Record<string, unknown>)
          : {};

    const element: AuthoredSpecElement = {
      type: node.component_id,
      props: translateInputsFromServer(rawInputs),
    };

    const slots = parentToSlots.get(node.uuid);
    if (slots && Object.keys(slots).length > 0) {
      element.slots = slots;
    }

    elements[node.uuid] = element;
  }

  return elements;
}

function safeParseInputs(raw: string): Record<string, unknown> {
  try {
    const parsed = JSON.parse(raw);
    return isRecord(parsed) ? (parsed as Record<string, unknown>) : {};
  } catch {
    return {};
  }
}

/**
 * Convert an authored element map back to the server's component_tree array.
 */
export function authoredElementsToComponentTree(
  elements: AuthoredSpecElementMap,
  componentVersions?: Map<string, string>,
): CanvasComponentTree {
  const keyToUuid = buildElementKeyToUuidMap(Object.keys(elements));
  const childToParent = buildChildToParentMap(elements);

  const tree: CanvasComponentTree = [];
  for (const [key, element] of Object.entries(elements)) {
    const parent = childToParent.get(key);
    const rawProps = isRecord(element.props)
      ? (element.props as Record<string, unknown>)
      : {};
    const node: Record<string, unknown> = {
      uuid: keyToUuid.get(key)!,
      component_id: element.type,
      component_version: componentVersions?.get(element.type) ?? '',
      inputs: translateInputsToServer(rawProps),
    };
    if (parent) {
      node.parent_uuid = keyToUuid.get(parent.parentKey) ?? undefined;
      node.slot = parent.slot;
    }
    tree.push(node as unknown as CanvasComponentTree[number]);
  }

  return tree;
}

export interface AuthoredContentTemplateFile {
  label: string;
  entityType: string;
  bundle: string;
  viewMode: string;
  elements: AuthoredSpecElementMap;
}

/**
 * Convert a server ContentTemplate to the authored JSON representation.
 */
export function contentTemplateToAuthored(
  template: ContentTemplate,
): AuthoredContentTemplateFile {
  const elements = componentTreeToAuthoredElements(
    template.component_tree ?? [],
  );
  return {
    label: template.label,
    entityType: template.entityType,
    bundle: template.bundle,
    viewMode: template.viewMode,
    elements,
  };
}
