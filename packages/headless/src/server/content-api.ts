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
import { parsePreviewRequest, withPreviewContext } from '../preview-context';
import { getSessionToken } from '../token';

import type { DraftData } from '../draft-data';
import type { PageResult } from '../page';
import type { PreviewContext } from '../preview-context';

/**
 * Fetches a page by its Drupal request URI (e.g. `/node/4?view=full`).
 *
 * With a draft session the request carries the session's user-bound bearer
 * token, so content the initiating editor may see (e.g. unpublished
 * entities) renders; without one — or once the session token has expired —
 * the request is anonymous and resolves only what anonymous visitors may
 * see. Returns null for anything the current access level cannot see
 * (403/404).
 * Rendering choices travel in this request URI or the explicit previewContext
 * option. The SDK strips reserved preview parameters before Drupal resolves
 * the route and forwards context only with a live session. Cookies supply
 * authorization, never rendering context.
 *
 * Drupal's route selects the entity to render;
 * the endpoint has no notion of JSON:API's resourceVersion.
 */
export async function fetchPage(
  requestUri: string,
  options: {
    baseUrl: string;
    draftData?: DraftData | null;
    componentPreviewId?: string;
    previewContext?: PreviewContext;
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
  const previewRequest = parsePreviewRequest(requestUri);
  url.searchParams.set('requestUri', previewRequest.requestUri);
  if (componentPreviewId) {
    url.searchParams.set(CANVAS_COMPONENT_PREVIEW_QUERY, componentPreviewId);
  }
  const requestContext =
    options.previewContext === undefined
      ? previewRequest
      : parsePreviewRequest(withPreviewContext('/', options.previewContext));
  // A component-library preview has no page template or content view mode.
  const context: PreviewContext = componentPreviewId
    ? { language: requestContext.language }
    : requestContext;
  if (liveDraft) {
    if (context.excludeAutoSave) {
      url.searchParams.set('excludeAutoSave', 'true');
    }
    for (const key of ['language', 'viewMode', 'pageVariant'] as const) {
      if (context[key]) {
        url.searchParams.set(key, context[key]);
      }
    }
  }
  const response = await fetchImpl(url, {
    headers,
    cache: 'no-store',
  });

  if (!response.ok) {
    return null;
  }
  const raw = (await response.json()) as PageResult;
  if (isPageRedirect(raw)) {
    // A local redirect remains inside this preview document. Keep its context
    // without adding frontend-only parameters to external destinations.
    if (liveDraft && !raw.redirect.external) {
      return {
        ...raw,
        redirect: {
          ...raw.redirect,
          url: withPreviewContext(raw.redirect.url, context),
        },
      };
    }
    return raw;
  }
  // Sites running a Canvas version that predates the context API answer
  // without `context`; components then see missing context rather than
  // fabricated values.
  const result: PageResult = {
    ...raw,
    context: raw.context ?? { page: null, site: null },
  };
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
