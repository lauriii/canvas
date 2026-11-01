// A namespace import: Next.js 15 exports cacheTag and cacheLife only with
// the unstable_ prefix, and a missing named import would break its build.
import * as nextCache from 'next/cache';

import type { Cacheability } from '@drupal-canvas/headless';

/** The most tags Next.js accepts per cacheTag() call; it drops the rest. */
const NEXT_CACHE_TAG_MAX_ITEMS = 128;

/**
 * Applies a Canvas result's Drupal cacheability to the enclosing
 * `'use cache'` scope: its cache tags, so that the `revalidate` route
 * handler expires it when Drupal invalidates any of them, and its max-age.
 * Call it inside the cached function, after fetching:
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
export function cacheCanvasTags(result: Cacheability): void {
  const cacheTag = nextCache.cacheTag ?? nextCache.unstable_cacheTag;
  const { cacheTags, cacheMaxAge } = result;
  for (let i = 0; i < cacheTags.length; i += NEXT_CACHE_TAG_MAX_ITEMS) {
    cacheTag(...cacheTags.slice(i, i + NEXT_CACHE_TAG_MAX_ITEMS));
  }
  if (cacheMaxAge >= 0) {
    const cacheLife = nextCache.cacheLife ?? nextCache.unstable_cacheLife;
    cacheLife({ revalidate: cacheMaxAge, expire: cacheMaxAge });
  }
}

/** Expires every cache entry tagged with one of the tags immediately. */
export function expireCanvasTags(tags: string[]): void {
  for (const tag of tags) {
    // Next.js 15 takes one argument and always expires immediately.
    nextCache.revalidateTag(tag, { expire: 0 });
  }
}
