/**
 * @file
 * The receiving end of Drupal's cache tag invalidation. After a request that
 * invalidates cache tags, the Canvas Headless module posts `{"id": "..."}` to
 * every configured frontend at `/api/canvas/revalidate`. The handler reads
 * the tags stored under that ID from the Drupal site (CANVAS_SITE_URL), so it
 * needs no shared secret: the announcement is only a hint, and the tags come
 * from Drupal itself. Content results carry the tags they depend on in
 * `cacheTags`; an application tags its cached copies with them, and expires
 * the copies here.
 */

import { resolveDraftConfig } from './config';

import type { DraftConfig } from './config';

export interface CacheInvalidationOptions {
  /**
   * Expires everything cached with any of the tags, for example through the
   * framework's tag cache or a CDN purge API.
   */
  invalidate(tags: string[]): void | Promise<void>;
  /** Configuration overrides; default: CANVAS_SITE_URL, read per request. */
  config?: Partial<DraftConfig>;
  /** Fetch implementation, injectable for tests. */
  fetchImpl?: typeof fetch;
}

/**
 * Creates the body of the cache invalidation route (POST
 * `/api/canvas/revalidate`). Answers 204 once `invalidate` completes, 400 for
 * a malformed announcement, 404 when Drupal knows no such ID, and 502 when
 * Drupal cannot be reached. Throws when the configuration is invalid or
 * `invalidate` throws.
 */
export function createCacheInvalidationHandler(
  options: CacheInvalidationOptions,
): (request: Request) => Promise<Response> {
  const { invalidate } = options;
  return async (request) => {
    if (request.method !== 'POST') {
      return new Response(null, { status: 405, headers: { Allow: 'POST' } });
    }
    const body = (await request.json().catch(() => null)) as {
      id?: unknown;
    } | null;
    const id = body?.id;
    if (typeof id !== 'string' || !/^[\w-]{1,128}$/.test(id)) {
      return new Response('Expected {"id": string}.', { status: 400 });
    }

    const { baseUrl } = resolveDraftConfig(options.config);
    // Resolved per request: frameworks may replace the global fetch later.
    const fetchImpl = options.fetchImpl ?? fetch;
    const response = await fetchImpl(
      `${baseUrl}/canvas-headless/cache-invalidation/${id}`,
      { headers: { Accept: 'application/json' }, cache: 'no-store' },
    ).catch(() => null);
    if (response?.status === 404) {
      return new Response('Unknown invalidation.', { status: 404 });
    }
    const tags = response?.ok
      ? ((await response.json().catch(() => null)) as { tags?: unknown } | null)
          ?.tags
      : null;
    if (
      !Array.isArray(tags) ||
      !tags.every((tag): tag is string => typeof tag === 'string')
    ) {
      return new Response('Could not read the invalidated tags from Drupal.', {
        status: 502,
      });
    }
    await invalidate(tags);
    return new Response(null, { status: 204 });
  };
}
