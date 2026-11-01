// A namespace import: Next.js 15 exports cacheTag only with the unstable_
// prefix, and a missing named import would break its build.
import * as nextCache from 'next/cache';

/** The most tags Next.js accepts per cacheTag() call; it drops the rest. */
const NEXT_CACHE_TAG_MAX_ITEMS = 128;

/**
 * The tags of a missing result: Drupal invalidates `4xx-response` whenever
 * content is created or updated, and `http_response` on router rebuilds.
 */
const NOT_FOUND_TAGS = ['4xx-response', 'http_response'];

/**
 * Tags the enclosing `'use cache'` scope with a Canvas result's Drupal cache
 * tags, so that the `revalidate` route handler expires it when Drupal
 * invalidates any of them. Call it inside the cached function, after
 * fetching; a `null` (not found) result expires when content changes:
 *
 * ```ts
 * async function getPublishedPage(path: string) {
 *   'use cache';
 *   const page = await fetchPublishedPage(path);
 *   cacheCanvasTags(page);
 *   return page;
 * }
 * ```
 */
export function cacheCanvasTags(result: { cacheTags: string[] } | null): void {
  const cacheTag = nextCache.cacheTag ?? nextCache.unstable_cacheTag;
  const tags = result?.cacheTags ?? NOT_FOUND_TAGS;
  for (let i = 0; i < tags.length; i += NEXT_CACHE_TAG_MAX_ITEMS) {
    cacheTag(...tags.slice(i, i + NEXT_CACHE_TAG_MAX_ITEMS));
  }
}

/** Expires every cache entry tagged with one of the tags immediately. */
export function expireCanvasTags(tags: string[]): void {
  for (const tag of tags) {
    // Next.js 15 takes one argument and always expires immediately.
    nextCache.revalidateTag(tag, { expire: 0 });
  }
}
