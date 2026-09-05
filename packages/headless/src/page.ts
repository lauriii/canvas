/**
 * @file
 * Isomorphic rendered-page contracts and helpers. These values describe the
 * JSON returned by Drupal and are safe to use in server and browser bundles.
 */

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
 * The cacheability of a rendered page: the cache tags its content depended
 * on. A consumer that records these per path can, on a publish notification,
 * map an invalidated tag back to the pages that must be rebuilt or
 * revalidated (for example an incremental static regeneration trigger).
 */
export interface PageCacheability {
  tags: string[];
}

/**
 * Drupal's resolved-and-rendered answer for a request URI.
 */
export interface Page {
  content: CanvasComponentTreeElement | null;
  head: PageHead;
  route: DrupalRoute;
  cacheability: PageCacheability;
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

/**
 * The `Surrogate-Key` header value for a page: its cache tags joined by
 * spaces, the format a CDN keys cached objects by for surrogate-key purging.
 * An adapter sets this on the page response so the CDN can later
 * purge exactly the objects a publish invalidated, by the same tags the
 * publish webhook carries. Returns an empty string when there are no tags,
 * so callers can skip setting the header.
 *
 * Cache tag names contain no spaces, so no escaping is needed.
 */
export function surrogateKeyHeader(page: Page): string {
  return page.cacheability.tags.join(' ');
}

/** Distinguishes redirect results without inspecting framework state. */
export function isPageRedirect(result: PageResult): result is PageRedirect {
  return 'redirect' in result;
}

/**
 * One element of the rendered content tree: element name, scalar props,
 * and slots containing rendered markup or nested elements.
 */
export interface CanvasComponentTreeElement {
  /**
   * Structural elements include `renderless-container` and the draft-only
   * `canvas-preview-content-region` that locates routed content inside page
   * chrome.
   */
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
