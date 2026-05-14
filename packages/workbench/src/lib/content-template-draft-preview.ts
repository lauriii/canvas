import type { Spec } from '@json-render/core';
import type {
  AuthoredSpecElement,
  AuthoredSpecElementMap,
} from './authored-spec-utils';
import type { ServerComponentShape } from './server-component-registry';

const SYNTHETIC_ROOT_TYPE = 'canvas:component-tree';

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

export interface DraftPreviewModel {
  [componentUuid: string]: {
    resolved?: Record<string, unknown>;
    source?: Record<string, unknown>;
    name?: string | null;
  };
}

export interface DraftPreviewResponse {
  model: DraftPreviewModel | Record<string, never>;
}

function isRecord(value: unknown): value is Record<string, unknown> {
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
 * until no unknown ancestors remain in the chain — handles nested
 * unknown-inside-unknown cases.
 *
 * Children kept in this returned tree retain their original UUIDs, so the
 * resolved server model can be looked up by UUID and applied back to the
 * original spec regardless of where in the tree the children ended up.
 */
function buildServerTreeWithoutUnknowns(
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

  // Build child → { parent uuid, slot } from authored slot links.
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

// Drupal's `/session/token` returns a base64url-style string. Reject anything
// that doesn't fit so we never put HTML/error pages into a header value.
const CSRF_TOKEN_PATTERN = /^[A-Za-z0-9_-]+$/;

async function fetchCsrfToken(signal?: AbortSignal): Promise<string | null> {
  try {
    const response = await fetch('/session/token', {
      credentials: 'include',
      ...(signal ? { signal } : {}),
    });
    if (!response.ok) return null;
    const token = (await response.text()).trim();
    return CSRF_TOKEN_PATTERN.test(token) ? token : null;
  } catch {
    return null;
  }
}

/**
 * POSTs a draft content template + preview entity to the Drupal layout
 * draft endpoint and returns the resolved input model.
 *
 * Caller-supplied `unknownUuids` get stripped from the server tree before
 * sending (with their slot children promoted) so the server resolves the
 * remaining tree normally without rejecting the request.
 *
 * Goes through the workbench dev server's `/canvas/api/` proxy (same-origin)
 * so authentication cookies and CORS work without extra setup. Fetches a
 * fresh CSRF token from `/session/token` (also proxied) for the mutating
 * request.
 */
export async function fetchDraftContentTemplatePreview(
  spec: Spec,
  metadata: { entityTypeId: string; bundle: string; viewMode: string },
  previewEntityId: string,
  unknownUuids: string[],
  componentVersions: Map<string, string>,
  signal?: AbortSignal,
): Promise<DraftPreviewResponse> {
  const { tree: componentTree, serverToSpec } = buildServerTreeWithoutUnknowns(
    spec,
    new Set(unknownUuids),
    componentVersions,
  );
  const csrfToken = await fetchCsrfToken(signal);
  // The preview entity ID is the entity's primary identifier (e.g. nid for
  // nodes), as returned by the suggestions endpoint.
  const url = `/canvas/api/v0/layout-content-template-draft/${encodeURIComponent(metadata.entityTypeId)}/${encodeURIComponent(previewEntityId)}?_format=json`;
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };
  if (csrfToken) {
    headers['X-CSRF-Token'] = csrfToken;
  }
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'include',
    headers,
    body: JSON.stringify({
      bundle: metadata.bundle,
      viewMode: metadata.viewMode,
      component_tree: componentTree,
    }),
    ...(signal ? { signal } : {}),
  });
  if (!response.ok) {
    const errorBody = (await response.json().catch(() => null)) as {
      message?: string;
    } | null;
    throw new Error(
      errorBody?.message ??
        `Draft content template preview request failed with status ${response.status}.`,
    );
  }
  const result = (await response.json()) as DraftPreviewResponse;

  // Remap server UUIDs back to original spec element keys.
  if (serverToSpec.size > 0 && isRecord(result.model)) {
    const remapped: DraftPreviewModel = {};
    for (const [serverUuid, value] of Object.entries(result.model)) {
      const specKey = serverToSpec.get(serverUuid) ?? serverUuid;
      remapped[specKey] = value;
    }
    result.model = remapped;
  }

  return result;
}

/**
 * Splices the server-resolved input values into element props so json-render
 * receives literal values instead of unresolved prop-source objects.
 */
export function applyResolved(
  spec: Spec,
  model: DraftPreviewModel | Record<string, never>,
): Spec {
  const elements = { ...(spec.elements ?? {}) };
  for (const [uuid, element] of Object.entries(elements)) {
    if (!element || element.type === SYNTHETIC_ROOT_TYPE) continue;
    const resolved = (model as DraftPreviewModel)[uuid]?.resolved;
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

export interface LocalComponentShape {
  propKeys: string[];
  slotKeys: string[];
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
 * different prop or slot keys — indicating local schema changes.
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
