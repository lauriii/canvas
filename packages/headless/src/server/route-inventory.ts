/**
 * @file
 * The client for the Canvas Headless module's route-inventory endpoint:
 * enumerate every path the anonymous content endpoint can serve, so a
 * static build knows which pages to prerender. Drupal Canvas Headless
 * exposes it at `/canvas/api/v0/headless/inventory`, paginated by an
 * opaque cursor. The endpoint path remains an implementation detail
 * confined to this file.
 */

/** One publicly routable path from the route inventory. */
export interface RouteInventoryEntry {
  /** The site-relative path, for example `/about`. */
  path: string;
  /** The entity type behind the route, for example `canvas_page`. */
  entityType: string;
  /** The entity id. */
  id: string;
  /** The entity UUID. */
  uuid: string;
  /** The entity language code. */
  langcode: string;
  /**
   * The last-changed instant as an ISO 8601 string, or null for an entity
   * type that does not track a changed timestamp.
   */
  changed: string | null;
}

/** One page of the inventory endpoint's JSON answer. */
interface RouteInventoryPage {
  paths: RouteInventoryEntry[];
  cursor: { next: string | null };
}

export interface RouteInventoryOptions {
  /** Base URL of the Drupal site, without a trailing slash. */
  baseUrl: string;
  /** Page size per request. Drupal defaults to 50 and caps it at 100. */
  limit?: number;
  /** Fetch implementation, injectable for tests. */
  fetchImpl?: typeof fetch;
}

/**
 * Fetches the complete route inventory: every published path the anonymous
 * content endpoint serves, with the identity of the entity behind it.
 * Walks the cursor pagination to completion, so the returned array is the
 * whole inventory regardless of page size.
 *
 * Shaped for build-time enumeration: feed the entries (or just their
 * paths, see fetchStaticPaths()) to Astro's `getStaticPaths`, Next.js's
 * `generateStaticParams`, or Nuxt's `nitro.prerender.routes`.
 *
 * Throws when Drupal answers non-200, naming the status and URL — a
 * silently truncated inventory would silently drop pages from the build.
 */
export async function fetchRouteInventory(
  options: RouteInventoryOptions,
): Promise<RouteInventoryEntry[]> {
  const { baseUrl, limit, fetchImpl = fetch } = options;

  const entries: RouteInventoryEntry[] = [];
  let cursor: string | null = null;
  do {
    const url = new URL(
      `${baseUrl.replace(/\/$/, '')}/canvas/api/v0/headless/inventory`,
    );
    if (limit !== undefined) {
      url.searchParams.set('limit', String(limit));
    }
    if (cursor !== null) {
      url.searchParams.set('cursor', cursor);
    }

    const response = await fetchImpl(url, {
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) {
      throw new Error(
        `The route inventory request failed with status ${response.status}: ${url}`,
      );
    }

    const page = (await response.json()) as RouteInventoryPage;
    entries.push(...page.paths);
    cursor = page.cursor.next;
  } while (cursor !== null);

  return entries;
}

/**
 * The route inventory reduced to its path strings — the shape route
 * enumeration hooks want: `getStaticPaths` (Astro), `generateStaticParams`
 * (Next.js), and `nitro.prerender.routes` (Nuxt) all take site-relative
 * paths.
 */
export async function fetchStaticPaths(
  options: RouteInventoryOptions,
): Promise<string[]> {
  return (await fetchRouteInventory(options)).map((entry) => entry.path);
}
