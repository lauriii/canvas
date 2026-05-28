import { randomUUID } from 'crypto';

import { isRecord } from './utils';

import type {
  AuthoredSpecElement,
  AuthoredSpecElementMap,
  CanvasComponentTree,
  canvasTreeToSpec,
} from 'drupal-canvas/json-render-utils';

// Strict RFC 4122 UUID match: version digit 1–5, variant digit 8/9/a/b. The
// loose 8-4-4-4-12 hex shape isn't sufficient because Drupal's UUID
// validator rejects values like "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa"
// (no proper version/variant). AI-generated specs sometimes ship those, so
// callers replace failures with a freshly generated v4.
const UUID_RE =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

/**
 * Returns true if `value` is an RFC 4122 v1–5 UUID. Mirrors the validation
 * Drupal's `Uuid` constraint applies server-side, so callers can decide
 * whether a string can be used as-is or needs to be replaced with a fresh
 * UUID before being pushed.
 */
export function isValidUuid(value: string): boolean {
  return UUID_RE.test(value);
}

/**
 * Builds a stable map from authored element keys to valid UUIDs. Keys that
 * already pass `isValidUuid` are kept; everything else is replaced with a
 * fresh v4 UUID. Used by the pages, regions and content-templates push
 * paths so authored specs (e.g. ones produced by an AI agent) with
 * placeholder keys still push successfully.
 */
export function buildElementKeyToUuidMap(
  elementKeys: Iterable<string>,
): Map<string, string> {
  const result = new Map<string, string>();
  for (const key of elementKeys) {
    result.set(key, isValidUuid(key) ? key : randomUUID());
  }
  return result;
}

/**
 * Reverse-walks `elements`' slot definitions and returns a map from each
 * child element key to `{ parentKey, slot }`. Elements that aren't named in
 * any parent's slots are absent from the map (i.e. root-level).
 */
export function buildChildToParentMap(
  elements: Record<string, { slots?: Record<string, string[]> }>,
): Map<string, { parentKey: string; slot: string }> {
  const result = new Map<string, { parentKey: string; slot: string }>();
  for (const [key, element] of Object.entries(elements)) {
    if (!element.slots) continue;
    for (const [slotName, childKeys] of Object.entries(element.slots)) {
      for (const childKey of childKeys) {
        result.set(childKey, { parentKey: key, slot: slotName });
      }
    }
  }
  return result;
}

/**
 * Converts a json-render spec to an authored element map suitable for page
 * and region spec files.
 *
 * The authored format differs from the json-render spec in two ways:
 * 1. The synthetic `canvas:component-tree` wrapper is stripped.
 * 2. `children` is merged into `slots.children` so all slot references are
 *    in a single map.
 */
export function jsonRenderSpecToAuthoredElementMap(
  spec: ReturnType<typeof canvasTreeToSpec>,
): AuthoredSpecElementMap {
  const elements: AuthoredSpecElementMap = {};

  for (const [key, element] of Object.entries(spec.elements)) {
    if (element.type === 'canvas:component-tree') continue;

    const slots: Record<string, string[]> = {};
    if (element.children && element.children.length > 0) {
      slots.children = [...element.children];
    }
    if (element.slots) {
      for (const [slotName, childKeys] of Object.entries(element.slots)) {
        slots[slotName] = [...childKeys];
      }
    }

    elements[key] = {
      type: element.type,
      props: isRecord(element.props) ? element.props : {},
      ...(Object.keys(slots).length > 0 ? { slots } : {}),
    };
  }

  return elements;
}

/**
 * Converts a server `component_tree` array into an authored element map.
 * Rebuilds each element's `slots` map by reverse-walking parent_uuid/slot
 * relationships.
 *
 * `transformInputs` is an optional per-node hook for callers that need to
 * normalize prop values on the way out — e.g. content templates unwrap
 * `{sourceType:'static:...', value:X}` to bare literals. Pages and regions
 * don't use prop expressions and skip it.
 */
export function componentTreeToAuthoredElementMap(
  tree: CanvasComponentTree,
  transformInputs?: (
    inputs: Record<string, unknown>,
  ) => Record<string, unknown>,
): AuthoredSpecElementMap {
  const elements: AuthoredSpecElementMap = {};

  const parentToSlots = new Map<string, Record<string, string[]>>();
  for (const node of tree) {
    if (!node.parent_uuid || !node.slot) continue;
    const slots = parentToSlots.get(node.parent_uuid) ?? {};
    const slotChildren = slots[node.slot] ?? [];
    slotChildren.push(node.uuid);
    slots[node.slot] = slotChildren;
    parentToSlots.set(node.parent_uuid, slots);
  }

  for (const node of tree) {
    // Older Canvas versions return `inputs` as a serialized JSON string
    // for config entities (ContentTemplate, PageRegion). The CLI ships
    // independently on npm and can target those sites, so the string
    // branch stays as a compat shim.
    const rawInputs: Record<string, unknown> =
      typeof node.inputs === 'string'
        ? parseLegacyStringInputs(node.inputs, node.uuid)
        : isRecord(node.inputs)
          ? (node.inputs as Record<string, unknown>)
          : {};

    const element: AuthoredSpecElement = {
      type: node.component_id,
      props: transformInputs ? transformInputs(rawInputs) : rawInputs,
    };

    const slots = parentToSlots.get(node.uuid);
    if (slots && Object.keys(slots).length > 0) {
      element.slots = slots;
    }

    elements[node.uuid] = element;
  }

  return elements;
}

function parseLegacyStringInputs(
  raw: string,
  nodeUuid: string,
): Record<string, unknown> {
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch (error) {
    throw new Error(
      `Failed to parse inputs JSON for component ${nodeUuid}: ${error instanceof Error ? error.message : String(error)}`,
    );
  }
  if (!isRecord(parsed)) {
    throw new Error(
      `Expected inputs for component ${nodeUuid} to be a JSON object, got ${parsed === null ? 'null' : typeof parsed}`,
    );
  }
  return parsed;
}

/**
 * Converts an authored element map back to the flat CanvasComponentTreeNode[]
 * array expected by the Canvas API. Rebuilds parent_uuid and slot
 * relationships by scanning each element's slots map.
 */
export function authoredElementMapToComponentTree(
  elements: AuthoredSpecElementMap,
  componentVersions?: Map<string, string>,
): CanvasComponentTree {
  const keyToUuid = buildElementKeyToUuidMap(Object.keys(elements));
  const childToParent = buildChildToParentMap(elements);

  const components: CanvasComponentTree = [];
  for (const [key, element] of Object.entries(elements)) {
    const parent = childToParent.get(key);
    components.push({
      uuid: keyToUuid.get(key)!,
      component_id: element.type,
      component_version: componentVersions?.get(element.type) ?? '',
      inputs: isRecord(element.props)
        ? (element.props as Record<string, unknown>)
        : {},
      parent_uuid: parent ? (keyToUuid.get(parent.parentKey) ?? null) : null,
      slot: parent?.slot ?? null,
      label: null,
    });
  }

  return components;
}
