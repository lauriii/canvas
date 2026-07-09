import { fetchCsrfToken } from './preview-client';
import { buildServerTreeWithoutUnknowns, isRecord } from './preview-spec-utils';

import type { Spec } from '@json-render/core';
import type { ResolvedPreviewModel } from './preview-spec-utils';

export interface DraftPreviewResponse {
  model: ResolvedPreviewModel | Record<string, never>;
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
  const url = `/canvas/api/v0/layout-content-template-draft/${encodeURIComponent(metadata.entityTypeId)}/${encodeURIComponent(previewEntityId)}`;
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
    const remapped: ResolvedPreviewModel = {};
    for (const [serverUuid, value] of Object.entries(result.model)) {
      const specKey = serverToSpec.get(serverUuid) ?? serverUuid;
      remapped[specKey] = value;
    }
    result.model = remapped;
  }

  return result;
}
