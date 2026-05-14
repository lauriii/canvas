export interface PreviewEntitySuggestion {
  /** The entity's primary ID (e.g. nid for nodes). */
  id: string;
  /** The entity's label. */
  label: string;
}

/**
 * Fetches a list of entities suitable for previewing a content template,
 * via the Canvas UI suggestions endpoint. The server returns up to 10
 * candidate entities of the target entity type + bundle that the current
 * user can view.
 *
 * The request goes through the workbench dev-server proxy at
 * `/canvas/api/...`, which injects an Authorization header server-side.
 */
export async function fetchPreviewEntitySuggestions(
  entityTypeId: string,
  bundle: string,
  signal?: AbortSignal,
): Promise<PreviewEntitySuggestion[]> {
  const url = `/canvas/api/v0/ui/content_template/suggestions/preview/${encodeURIComponent(entityTypeId)}/${encodeURIComponent(bundle)}?_format=json`;
  const response = await fetch(url, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
    ...(signal ? { signal } : {}),
  });
  if (!response.ok) {
    const body = (await response.json().catch(() => null)) as {
      message?: string;
    } | null;
    throw new Error(
      body?.message ??
        `Failed to load preview entity suggestions (status ${response.status}).`,
    );
  }
  const data = (await response.json()) as unknown;
  // The endpoint returns a JSON object keyed by entity ID — PHP serializes
  // entity-id-keyed maps as objects, not arrays. Tolerate both shapes.
  const items: unknown[] = Array.isArray(data)
    ? data
    : data && typeof data === 'object'
      ? Object.values(data as Record<string, unknown>)
      : [];
  return items
    .map((item): PreviewEntitySuggestion | null => {
      if (!item || typeof item !== 'object') return null;
      const record = item as Record<string, unknown>;
      const id =
        typeof record.id === 'string' || typeof record.id === 'number'
          ? String(record.id)
          : null;
      if (!id) return null;
      const label =
        typeof record.label === 'string' ? record.label : '(untitled)';
      return { id, label };
    })
    .filter((item): item is PreviewEntitySuggestion => item !== null);
}
