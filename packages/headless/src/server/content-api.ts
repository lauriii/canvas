/**
 * @file
 * The client for the Canvas Headless module's rendered-content endpoint:
 * resolve a Drupal request URI and get the routed content back as structured
 * data. Drupal Canvas Headless exposes it at
 * `/canvas/content-api?requestUri={requestUri}`. The endpoint path remains an
 * implementation detail confined to this file so the SDK's public surface
 * describes what the caller gets rather than how Drupal serves it.
 */

import { getSessionToken } from '../token';

import type { DraftData } from '../draft-data';

/**
 * A JSON value. The page payload is parsed JSON, so its loosely shaped
 * members are typed as JSON rather than `unknown`: it says exactly what
 * they can hold, and frameworks that check values crossing the server
 * boundary for serializable types (TanStack Start's server functions)
 * accept it.
 */
export type JsonValue =
  | string
  | number
  | boolean
  | null
  | JsonValue[]
  | { [key: string]: JsonValue };

/** Scalar attributes for one document meta tag. */
export type PageHeadMeta = Record<string, string>;

/** Scalar attributes for one non-stylesheet document link tag. */
export type PageHeadLink = Record<string, string> & {
  rel: string;
  href: string;
};

/** One inert JSON-LD data script. */
export interface PageHeadScript {
  [dataAttribute: `data-${string}`]: never;
  type: 'application/ld+json';
  textContent: JsonValue[] | { [key: string]: JsonValue };
}

/** The filtered Unhead-compatible document head returned by Drupal. */
export interface PageHead {
  title: string;
  meta?: PageHeadMeta[];
  link?: PageHeadLink[];
  script?: PageHeadScript[];
}

/** Identity-only metadata for the rendered Drupal entity. */
export interface DrupalRouteEntity {
  entityType: string;
  bundle: string;
  id: string;
  uuid: string;
  langcode: string;
}

/** The Drupal route that was resolved for the requested frontend URI. */
export interface DrupalRoute {
  name: string;
  requestUri: string;
  params: Record<string, string>;
  /** Whether Canvas manages the route's complete component tree. */
  managedByCanvas: boolean;
  entity: DrupalRouteEntity | null;
}

/**
 * Drupal's resolved-and-rendered answer for a request URI.
 */
export interface Page {
  content: CanvasComponentTreeElement | null;
  head: PageHead;
  route: DrupalRoute;
}

/** A redirect Drupal resolved before routed content. */
export interface PageRedirect {
  redirect: {
    external: boolean;
    url: string;
    statusCode: number;
  };
}

/** Drupal's content or redirect result for one frontend request URI. */
export type PageResult = Page | PageRedirect;

/** Distinguishes redirect results without inspecting framework state. */
export function isPageRedirect(result: PageResult): result is PageRedirect {
  return 'redirect' in result;
}

/**
 * One element of the rendered content tree: element name, scalar props,
 * and slots containing rendered markup or nested elements.
 */
export interface CanvasComponentTreeElement {
  element: string;
  props?: Record<string, JsonValue>;
  slots?: Record<string, CanvasComponentTreeSlot>;
  /** SDK render context: present while the draft/editor session is enabled. */
  canvasDraftMode?: true;
}

/**
 * Slot values emitted by the Custom Elements API. A slot with one child is
 * serialized as that value; a multi-value slot is serialized as an array.
 * Drupal render arrays can preserve nested child groups, so arrays may be
 * nested while retaining their render order.
 */
export type CanvasComponentTreeSlot =
  | string
  | CanvasComponentTreeElement
  | CanvasComponentTreeSlot[];

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
    fetchImpl?: typeof fetch;
  },
): Promise<PageResult | null> {
  const { baseUrl, draftData, fetchImpl = fetch } = options;

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

/**
 * Serializes JSON for an inline data script without creating HTML markup.
 */
export function serializeJsonForHtml(value: JsonValue): string {
  return JSON.stringify(value)
    .replace(/</g, '\\u003C')
    .replace(/>/g, '\\u003E')
    .replace(/&/g, '\\u0026')
    .replace(/\u2028/g, '\\u2028')
    .replace(/\u2029/g, '\\u2029');
}
