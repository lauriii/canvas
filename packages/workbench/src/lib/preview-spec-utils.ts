import type { Spec } from '@json-render/core';
import type {
  AuthoredSpecElement,
  AuthoredSpecElementMap,
} from './authored-spec-utils';
import type { ServerComponentShape } from './server-component-registry';

export const SYNTHETIC_ROOT_TYPE = 'canvas:component-tree';

const UUID_RE =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function isValidUuid(value: string): boolean {
  return UUID_RE.test(value);
}

interface ComponentTreeNode {
  uuid: string;
  component_id: string;
  component_version: string;
  inputs: Record<string, unknown>;
  parent_uuid?: string;
  slot?: string;
}

export interface LocalComponentShape {
  propKeys: string[];
  slotKeys: string[];
}

export interface ResolvedPreviewModel {
  [componentUuid: string]: {
    resolved?: Record<string, unknown>;
    source?: Record<string, unknown>;
    name?: string | null;
  };
}

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function elementInputs(element: AuthoredSpecElement): Record<string, unknown> {
  if (!isRecord(element.props)) return {};
  const inputs = { ...element.props };
  if (isRecord(element._provenance)) {
    for (const [key, value] of Object.entries(element._provenance)) {
      if (key in inputs) {
        inputs[key] = value;
      }
    }
  }
  return inputs;
}

/**
 * Strips the workbench's synthetic `canvas:component-tree` root element from
 * a Spec and returns a plain authored element map.
 */
function specToAuthoredElements(spec: Spec): AuthoredSpecElementMap {
  const result: AuthoredSpecElementMap = {};
  for (const [uuid, element] of Object.entries(spec.elements ?? {})) {
    if (element?.type === SYNTHETIC_ROOT_TYPE) continue;
    result[uuid] = element as AuthoredSpecElement;
  }
  return result;
}

/**
 * Returns the UUIDs of elements whose `type` is not present in the
 * `registered` set. The synthetic root is excluded.
 */
export function findUnknownElementUuids(
  spec: Spec,
  registered: Set<string>,
): string[] {
  const result: string[] = [];
  for (const [uuid, element] of Object.entries(spec.elements ?? {})) {
    if (!element || element.type === SYNTHETIC_ROOT_TYPE) continue;
    if (!registered.has(element.type)) {
      result.push(uuid);
    }
  }
  return result;
}

/**
 * Builds a server-format component_tree from the workbench's spec, with
 * unknown UUIDs removed. Slot children of removed items are promoted to
 * the unknown's parent (or to root if the unknown was a root). Repeats
 * until no unknown ancestors remain in the chain - handles nested
 * unknown-inside-unknown cases.
 *
 * Children kept in this returned tree retain their original UUIDs, so the
 * resolved server model can be looked up by UUID and applied back to the
 * original spec regardless of where in the tree the children ended up.
 */
export function buildServerTreeWithoutUnknowns(
  spec: Spec,
  unknownUuids: Set<string>,
  componentVersions: Map<string, string>,
): { tree: ComponentTreeNode[]; serverToSpec: Map<string, string> } {
  const elements = specToAuthoredElements(spec);

  // Remap non-UUID element keys to valid UUIDs for the server request.
  const specToServer = new Map<string, string>();
  const serverToSpec = new Map<string, string>();
  for (const key of Object.keys(elements)) {
    const serverKey = isValidUuid(key) ? key : crypto.randomUUID();
    specToServer.set(key, serverKey);
    serverToSpec.set(serverKey, key);
  }

  // Build child -> { parent uuid, slot } from authored slot links.
  const childToParent = new Map<string, { parent: string; slot: string }>();
  for (const [uuid, element] of Object.entries(elements)) {
    if (!element.slots) continue;
    for (const [slotName, childUuids] of Object.entries(element.slots)) {
      for (const child of childUuids) {
        childToParent.set(child, { parent: uuid, slot: slotName });
      }
    }
  }

  // For each known UUID, find the closest non-unknown ancestor's
  // (parent, slot). If the chain only has unknowns, the element becomes a
  // root in the server tree.
  const resolveAttachment = (
    uuid: string,
  ): { parent: string; slot: string } | null => {
    let current = childToParent.get(uuid);
    while (current && unknownUuids.has(current.parent)) {
      current = childToParent.get(current.parent);
    }
    return current ?? null;
  };

  const tree: ComponentTreeNode[] = [];
  for (const [uuid, element] of Object.entries(elements)) {
    if (unknownUuids.has(uuid)) continue;
    const serverUuid = specToServer.get(uuid) ?? uuid;
    const node: ComponentTreeNode = {
      uuid: serverUuid,
      component_id: element.type,
      component_version: componentVersions.get(element.type) ?? '',
      inputs: elementInputs(element),
    };
    const attach = resolveAttachment(uuid);
    if (attach) {
      node.parent_uuid = specToServer.get(attach.parent) ?? attach.parent;
      node.slot = attach.slot;
    }
    tree.push(node);
  }
  return { tree, serverToSpec };
}

/**
 * Splices the server-resolved input values into element props so json-render
 * receives literal values instead of unresolved prop-source objects.
 */
export function applyResolved(
  spec: Spec,
  model: ResolvedPreviewModel | Record<string, never>,
): Spec {
  const elements = { ...(spec.elements ?? {}) };
  for (const [uuid, element] of Object.entries(elements)) {
    if (!element || element.type === SYNTHETIC_ROOT_TYPE) continue;
    const resolved = (model as ResolvedPreviewModel)[uuid]?.resolved;
    if (!resolved) continue;
    const existingProps = isRecord(element.props) ? element.props : {};
    const merged: Record<string, unknown> = { ...existingProps };
    for (const [key, value] of Object.entries(resolved)) {
      if (
        value == null &&
        isRecord(existingProps[key]) &&
        !('sourceType' in existingProps[key])
      ) {
        continue;
      }
      merged[key] = value;
    }
    elements[uuid] = {
      ...element,
      props: merged,
    };
  }
  return { ...spec, elements };
}

export function applyResolvedToElementsOfType(
  spec: Spec,
  componentNames: Set<string>,
  resolved: Record<string, unknown>,
): Spec {
  const model: ResolvedPreviewModel = {};
  for (const [uuid, element] of Object.entries(spec.elements ?? {})) {
    if (!element || element.type === SYNTHETIC_ROOT_TYPE) continue;
    if (!componentNames.has(element.type)) continue;
    model[uuid] = { resolved };
  }
  return applyResolved(spec, model);
}

export async function getLocalComponentShapes(
  signal?: AbortSignal,
): Promise<Map<string, LocalComponentShape>> {
  const response = await fetch('/__canvas/components-metadata', {
    headers: { Accept: 'application/json' },
    ...(signal ? { signal } : {}),
  });
  if (!response.ok) return new Map();
  const data = (await response.json()) as Record<
    string,
    { propKeys: string[]; slotKeys: string[] }
  >;
  const map = new Map<string, LocalComponentShape>();
  for (const [id, shape] of Object.entries(data)) {
    map.set(id, shape);
  }
  return map;
}

function arraysEqual(a: string[], b: string[]): boolean {
  if (a.length !== b.length) return false;
  for (let i = 0; i < a.length; i++) {
    if (a[i] !== b[i]) return false;
  }
  return true;
}

/**
 * Returns component type IDs that exist on both server and locally but have
 * different prop or slot keys - indicating local schema changes.
 */
export function findComponentsWithLocalChanges(
  spec: Spec,
  serverShapes: Map<string, ServerComponentShape>,
  localShapes: Map<string, LocalComponentShape>,
  unknownUuids: Set<string>,
): string[] {
  const changed = new Set<string>();
  for (const [uuid, element] of Object.entries(spec.elements ?? {})) {
    if (!element || element.type === SYNTHETIC_ROOT_TYPE) continue;
    if (unknownUuids.has(uuid)) continue;
    const type = element.type;
    if (changed.has(type)) continue;
    const server = serverShapes.get(type);
    const local = localShapes.get(type);
    if (!server || !local) continue;
    if (
      !arraysEqual(server.propKeys, local.propKeys) ||
      !arraysEqual(server.slotKeys, local.slotKeys)
    ) {
      changed.add(type);
    }
  }
  return Array.from(changed);
}
