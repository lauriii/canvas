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
import type { Cacheability, PageNotFound, PageResult } from '../page';
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
  const { response, draftContext } = await requestPage(requestUri, options);
  if (!response.ok) {
    return null;
  }
  return toPageResult(await response.json(), draftContext);
}

/**
 * Fetches a page as anonymous visitors see it (see fetchPage()), for caches
 * keyed by path. A page anonymous visitors cannot see (403/404) is a
 * PageNotFound with the error's cacheability; other errors throw, so that a
 * cache does not keep a transient failure.
 */
export async function fetchPublishedPage(
  requestUri: string,
  options: { baseUrl: string; fetchImpl?: typeof fetch },
): Promise<PageResult | PageNotFound> {
  const { response } = await requestPage(requestUri, options);
  if (response.status === 403 || response.status === 404) {
    const problem = (await response
      .json()
      .catch(() => ({}))) as Partial<Cacheability>;
    return {
      notFound: true,
      status: response.status,
      // Sites predating cacheability in errors: Drupal invalidates
      // 4xx-response when content is created or changed.
      cacheTags: problem.cacheTags ?? ['4xx-response', 'http_response'],
      cacheMaxAge: problem.cacheMaxAge ?? -1,
    };
  }
  if (!response.ok) {
    throw new Error(`The Canvas content API answered ${response.status}.`);
  }
  return toPageResult(await response.json(), null);
}

/**
 * Requests a page from the content API. Returns the response, and the preview
 * context to apply to the result when the request carried a live draft
 * session.
 */
async function requestPage(
  requestUri: string,
  options: Parameters<typeof fetchPage>[1],
): Promise<{ response: Response; draftContext: PreviewContext | null }> {
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
  return { response, draftContext: liveDraft ? context : null };
}

/** Normalizes a content API result, adding preview state for a draft. */
function toPageResult(
  raw: PageResult,
  draftContext: PreviewContext | null,
): PageResult {
  // Sites running a Canvas version that predates cache tags answer without
  // them; nothing then expires on invalidation.
  const page: PageResult = {
    ...raw,
    cacheTags: raw.cacheTags ?? [],
    cacheMaxAge: raw.cacheMaxAge ?? -1,
  };
  if (isPageRedirect(page)) {
    // A local redirect remains inside this preview document. Keep its context
    // without adding frontend-only parameters to external destinations.
    if (draftContext && !page.redirect.external) {
      return {
        ...page,
        redirect: {
          ...page.redirect,
          url: withPreviewContext(page.redirect.url, draftContext),
        },
      };
    }
    return page;
  }
  // Sites running a Canvas version that predates the context API answer
  // without `context`; components then see missing context rather than
  // fabricated values.
  const result: PageResult = {
    ...page,
    context: page.context ?? { page: null, site: null },
  };
  if (draftContext && result.route.managedByCanvas) {
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
