/**
 * @file
 * The client for the Canvas Headless module's rendered-content endpoint:
 * resolve a Drupal request URI and get the routed content back as structured
 * data. Drupal Canvas Headless exposes it at
 * `/canvas/content-api?requestUri={requestUri}`. The endpoint path remains an
 * implementation detail confined to this file so the SDK's public surface
 * describes what the caller gets rather than how Drupal serves it.
 */

import { CANVAS_COMPONENT_PREVIEW_QUERY } from '../constants';
import { isPageRedirect } from '../page';
import { getSessionToken } from '../token';

import type { DraftData } from '../draft-data';
import type { PageResult } from '../page';

/**
 * Fetches a page by its Drupal request URI (e.g. `/node/4?view=full`).
 *
 * With a draft session the request carries the session's user-bound bearer
 * token, so content the initiating editor may see (e.g. unpublished
 * entities) renders; without one — or once the session token has expired —
 * the request is anonymous and resolves only what anonymous visitors may
 * see. Returns null for anything the current access level cannot see
 * (403/404).
 *
 * The endpoint renders through Drupal's routing, so the default revision
 * is served; it has no notion of JSON:API's resourceVersion.
 */
export async function fetchPage(
  requestUri: string,
  options: {
    baseUrl: string;
    draftData?: DraftData | null;
    componentPreviewId?: string;
    fetchImpl?: typeof fetch;
  },
): Promise<PageResult | null> {
  const { baseUrl, draftData, componentPreviewId, fetchImpl = fetch } = options;

  const headers: Record<string, string> = { Accept: 'application/json' };
  let liveDraft = false;
  if (draftData) {
    const token = getSessionToken(draftData);
    if (token) {
      liveDraft = true;
      headers.Authorization = `${token.tokenType} ${token.value}`;
    }
    // Expired session: stay anonymous; the draft indicator surfaces it.
  }

  const url = new URL(`${baseUrl.replace(/\/$/, '')}/canvas/content-api`);
  url.searchParams.set('requestUri', requestUri);
  if (componentPreviewId) {
    url.searchParams.set(CANVAS_COMPONENT_PREVIEW_QUERY, componentPreviewId);
  }
  if (liveDraft && draftData?.previewContext?.viewMode) {
    url.searchParams.set('viewMode', draftData.previewContext.viewMode);
  }
  const response = await fetchImpl(url, {
    headers,
    cache: 'no-store',
  });

  if (!response.ok) {
    return null;
  }
  const result = (await response.json()) as PageResult;
  if (isPageRedirect(result)) {
    return result;
  }
  if (liveDraft && result.route.managedByCanvas) {
    return {
      ...result,
      content: {
        ...(result.content ?? { element: 'renderless-container' }),
        canvasDraftMode: true,
      },
    };
  }
  return result;
}
