import type { PreviewRenderCanvasData } from './preview-contract';

/**
 * Fetches the page-level `canvasData.v0` fields (`pageTitle`, `breadcrumbs`,
 * `mainEntity` with translation metadata) for a target entity from the Drupal
 * page-data endpoint.
 *
 * Goes through the workbench dev server's `/canvas/api/` proxy (same-origin)
 * so authentication cookies work without extra setup. A plain GET: no CSRF
 * token needed.
 */
export async function fetchPreviewPageData(
  entityTypeId: string,
  entityId: string,
  signal?: AbortSignal,
): Promise<PreviewRenderCanvasData> {
  const url = `/canvas/api/v0/page-data/${encodeURIComponent(entityTypeId)}/${encodeURIComponent(entityId)}`;
  const response = await fetch(url, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
    ...(signal ? { signal } : {}),
  });
  if (!response.ok) {
    const errorBody = (await response.json().catch(() => null)) as {
      message?: string;
    } | null;
    throw new Error(
      errorBody?.message ??
        `Page data request failed with status ${response.status}.`,
    );
  }
  return (await response.json()) as PreviewRenderCanvasData;
}
