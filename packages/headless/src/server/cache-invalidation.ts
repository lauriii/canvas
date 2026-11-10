/**
 * @file
 * The receiving end of Drupal's cache tag invalidation. After a request that
 * invalidates cache tags, the Canvas Headless module posts `{"id": "..."}` to
 * `/api/canvas/revalidate` under the URL of every frontend registered in
 * Canvas. Nothing is turned on in Drupal. The handler reads the tags stored
 * under that ID from the Drupal site (CANVAS_SITE_URL), once, so it needs no
 * shared secret: the announcement is only a hint, and the tags come from Drupal
 * itself. Drupal notices an announcement whose tags are never read, and later
 * announces `http_response`, which expires everything. Content results carry
 * the tags they depend on in `cacheTags`; an application tags its cached copies
 * with them, and expires the copies here.
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
  /**
   * Runs a task after the response, for example Next.js `after()`. With it,
   * the handler answers 202 at once and reads the tags afterwards: Drupal's
   * announcing request then never waits for a Drupal worker to answer the
   * read. A failure is logged. A failed read expires nothing: Drupal notices
   * that the tags were not read, and announces `http_response` later. When
   * `invalidate` throws after the tags were read, the handler expires
   * everything (`http_response`) instead, because Drupal counts them as
   * delivered.
   */
  defer?: (task: () => Promise<void>) => void;
}

/**
 * Creates the body of the cache invalidation route. Mount it as POST
 * `/api/canvas/revalidate` under the frontend URL registered in Canvas: Drupal
 * announces to that path only. Answers 204 once `invalidate` completes, 400
 * for a malformed announcement, 404 when Drupal knows no such ID, and 502 when
 * Drupal cannot be reached or does not answer within ten seconds. Throws when
 * the configuration is invalid or `invalidate` throws. With `defer`, answers
 * 202 for every well-formed announcement instead.
 */
export function createCacheInvalidationHandler(
  options: CacheInvalidationOptions,
): (request: Request) => Promise<Response> {
  const { invalidate, defer } = options;
  const invalidateAnnounced = async (
    id: string,
    expire: CacheInvalidationOptions['invalidate'],
  ): Promise<Response> => {
    const { baseUrl } = resolveDraftConfig(options.config);
    // Resolved per request: frameworks may replace the global fetch later.
    const fetchImpl = options.fetchImpl ?? fetch;
    // The ID travels in the body, so that access logs never record it.
    const response = await fetchImpl(
      `${baseUrl}/canvas-headless/cache-invalidation`,
      {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ id }),
        cache: 'no-store',
        signal: AbortSignal.timeout(10_000),
      },
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
    await expire(tags);
    return new Response(null, { status: 204 });
  };
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
    if (!defer) {
      return invalidateAnnounced(id, invalidate);
    }
    defer(async () => {
      try {
        const response = await invalidateAnnounced(id, async (tags) => {
          try {
            await invalidate(tags);
          } catch (error) {
            console.error('Canvas cache invalidation failed:', error);
            // Drupal counts tags that were read as delivered: expire everything.
            await invalidate(['http_response']);
          }
        });
        // 404: a forged or replayed ID. A failed read expires nothing either:
        // Drupal notices the unread tags and announces `http_response` later.
        if (!response.ok && response.status !== 404) {
          console.warn(
            `Canvas cache invalidation failed: ${response.status} ${await response.text()}`,
          );
        }
      } catch (error) {
        console.error('Canvas cache invalidation failed:', error);
      }
    });
    return new Response(null, { status: 202 });
  };
}
