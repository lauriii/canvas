import { canvasTreeToSpec } from 'drupal-canvas/json-render-utils';

import { isRecord } from './utils';

import type {
  AuthoredSpecElementMap,
  CanvasComponentTree,
} from 'drupal-canvas/json-render-utils';
import type { Page } from '../types/Page';

function isResolvedMediaValue(
  value: unknown,
): value is Record<string, unknown> {
  return isRecord(value) && typeof value.src === 'string';
}

function isMediaProvenanceValue(value: unknown): boolean {
  return (
    isRecord(value) &&
    (typeof value.target_id === 'number' ||
      typeof value.target_id === 'string' ||
      typeof value.target_uuid === 'string')
  );
}

function extractMediaProvenance(
  inputs: Record<string, unknown>,
  resolvedInputs: Record<string, unknown>,
): Record<string, unknown> | undefined {
  const provenance = Object.fromEntries(
    Object.entries(inputs).filter(([key, value]) => {
      return (
        isResolvedMediaValue(resolvedInputs[key]) &&
        isMediaProvenanceValue(value)
      );
    }),
  );

  return Object.keys(provenance).length > 0 ? provenance : undefined;
}

/**
 * Converts a json-render spec to an authored element map suitable for page
 * spec files.
 *
 * The authored format differs from the json-render spec in two ways:
 * 1. The synthetic `canvas:component-tree` wrapper is stripped.
 * 2. `children` is merged into `slots.children` so all slot references are
 *    in a single map.
 *
 * @param spec - json-render spec (typically from `canvasTreeToSpec`)
 * @returns A flat map of authored elements keyed by element ID
 */
export function specToAuthoredElementMap(
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
      props: element.props ?? {},
      ...(Object.keys(slots).length > 0 ? { slots } : {}),
    };
  }

  return elements;
}

/**
 * Converts an authored element map back to the flat CanvasComponentTreeNode[] array
 * expected by the Canvas API.
 *
 * This is the reverse of pageToAuthoredSpec: it rebuilds parent_uuid and slot
 * relationships by scanning each element's slots map.
 */
export function authoredSpecToComponentTree(
  elements: AuthoredSpecElementMap,
  componentVersions?: Map<string, string>,
): CanvasComponentTree {
  // Build a reverse lookup: child UUID → { parentUuid, slotName }
  const childToParent = new Map<string, { parentUuid: string; slot: string }>();

  for (const [uuid, element] of Object.entries(elements)) {
    if (!element.slots) continue;
    for (const [slotName, childUuids] of Object.entries(element.slots)) {
      for (const childUuid of childUuids) {
        childToParent.set(childUuid, { parentUuid: uuid, slot: slotName });
      }
    }
  }

  const components: CanvasComponentTree = [];
  for (const [uuid, element] of Object.entries(elements)) {
    const parent = childToParent.get(uuid);
    components.push({
      uuid,
      component_id: element.type,
      component_version: componentVersions?.get(element.type) ?? '',
      inputs: (element.props as Record<string, unknown>) ?? {},
      parent_uuid: parent?.parentUuid ?? null,
      slot: parent?.slot ?? null,
      label: null,
    });
  }

  return components;
}

export function pageToAuthoredSpec(page: Page): Record<string, unknown> {
  if (page.components.length === 0) {
    return { uuid: page.uuid, title: page.title, elements: {} };
  }

  const components = page.components.map((node) => ({
    ...node,
    inputs: node.inputs_resolved ?? ({} as Record<string, unknown>),
  }));

  const spec = canvasTreeToSpec(components);
  const elements = specToAuthoredElementMap(spec);

  for (const node of page.components) {
    const element = elements[node.uuid];
    if (!element) {
      continue;
    }

    const provenance = extractMediaProvenance(
      isRecord(node.inputs) ? node.inputs : {},
      isRecord(node.inputs_resolved) ? node.inputs_resolved : {},
    );
    if (provenance) {
      element._provenance = provenance;
    }
  }

  return { uuid: page.uuid, title: page.title, elements };
}
