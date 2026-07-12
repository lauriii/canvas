import { getDrupalDraftConfig } from "./config";
import { getDraftData } from "./draft-data";
import { getSessionToken } from "./token";

/**
 * The response shape of the Lupus Decoupled CE API endpoint
 * (`/ce-api/{drupal-path}`): the entity rendered as a custom element by the
 * custom_elements module, plus page-level data assembled by
 * lupus_ce_renderer.
 */
export interface CeApiPage {
  title: string;
  content_format: "json" | "markup";
  content: CeElement | string;
  breadcrumbs?: Array<{ url: string; label: string; frontpage?: boolean }>;
  metatags?: Record<string, unknown>;
  page_layout?: string;
  local_tasks?: unknown[];
  messages?: unknown[];
}

/**
 * A custom element in JSON format: element name, scalar props, and slots
 * containing rendered markup or nested elements.
 */
export interface CeElement {
  element: string;
  props?: Record<string, string>;
  slots?: Record<string, Array<string | CeElement>>;
}

/**
 * Fetches a page from the CE API by its Drupal path (e.g. `/node/4`).
 *
 * In draft mode the request carries the session's user-bound bearer token,
 * so content the initiating editor may see (e.g. unpublished entities)
 * renders; outside draft mode — or once the session token has expired —
 * the request is anonymous and resolves only what anonymous visitors may
 * see. Returns null for anything the current access level cannot see
 * (403/404).
 *
 * The CE API renders through Drupal's routing, so the default revision is
 * served; it has no notion of JSON:API's resourceVersion.
 */
export async function fetchCeApiPage(path: string): Promise<CeApiPage | null> {
  const config = getDrupalDraftConfig();
  const draftData = await getDraftData();

  const headers: HeadersInit = { Accept: "application/json" };
  if (draftData) {
    const token = getSessionToken(draftData);
    if (token) {
      headers.Authorization = `${token.tokenType} ${token.value}`;
    }
    // Expired session: stay anonymous; the draft indicator surfaces it.
  }

  const response = await fetch(`${config.baseUrl}/ce-api${path}`, {
    headers,
    cache: "no-store",
  });

  if (!response.ok) {
    return null;
  }
  return (await response.json()) as CeApiPage;
}
