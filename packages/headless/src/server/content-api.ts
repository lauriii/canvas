/**
 * @file
 * The client for the Canvas Headless module's rendered-content endpoint:
 * resolve a Drupal path and get the routed content back as structured
 * data. The endpoint is currently provided by the Lupus Decoupled CE API
 * stack the module depends on (custom_elements + lupus_ce_renderer, at
 * `/ce-api/{path}`) — an implementation detail confined to this file:
 * names here describe what the caller gets, not who serves it, so the
 * serving stack can change without touching the SDK's surface.
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

/**
 * Drupal's resolved-and-rendered answer for a path: the routed entity
 * rendered as a structured element tree (or markup), plus page-level data.
 */
export interface Page {
  title: string;
  content_format: 'json' | 'markup';
  content: CanvasComponentTreeElement | string;
  breadcrumbs?: Array<{ url: string; label: string; frontpage?: boolean }>;
  metatags?: Record<string, JsonValue>;
  page_layout?: string;
  local_tasks?: JsonValue[];
  messages?: JsonValue[];
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
 */
export type CanvasComponentTreeSlot =
  | string
  | CanvasComponentTreeElement
  | Array<string | CanvasComponentTreeElement>;

/**
 * Fetches a page by its Drupal path (e.g. `/node/4`).
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
  path: string,
  options: {
    baseUrl: string;
    draftData?: DraftData | null;
    fetchImpl?: typeof fetch;
  },
): Promise<Page | null> {
  const { baseUrl, draftData, fetchImpl = fetch } = options;

  const headers: HeadersInit = { Accept: 'application/json' };
  if (draftData) {
    const token = getSessionToken(draftData);
    if (token) {
      headers.Authorization = `${token.tokenType} ${token.value}`;
    }
    // Expired session: stay anonymous; the draft indicator surfaces it.
  }

  const response = await fetchImpl(`${baseUrl}/ce-api${path}`, {
    headers,
    cache: 'no-store',
  });

  if (!response.ok) {
    return null;
  }
  const page = (await response.json()) as Page;
  if (draftData && typeof page.content !== 'string') {
    return {
      ...page,
      content: { ...page.content, canvasDraftMode: true },
    };
  }
  return page;
}
