// A namespace import: the package supports Next.js 15, which lacks cacheTag
// and cacheLife, and a missing named import would break its build.
import * as nextCache from 'next/cache';

import type { Cacheability } from '@drupal-canvas/headless';

/** The most tags Next.js accepts per cacheTag() call; it drops the rest. */
const NEXT_CACHE_TAG_MAX_ITEMS = 128;

/**
 * Applies the Drupal cacheability of Canvas results to the enclosing
 * `'use cache'` scope: all their cache tags, so that the cache invalidation
 * route expires it when Drupal invalidates any of them, and the shortest
 * max-age. Call it once per scope, after fetching, with every result the
 * entry depends on. Needs Next.js 16 Cache Components.
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
export function cacheCanvasTags(...results: Cacheability[]): void {
  const { cacheTag, cacheLife } = nextCache;
  if (!cacheTag || !cacheLife) {
    throw new Error('cacheCanvasTags() needs Next.js 16 Cache Components.');
  }
  const cacheTags = [...new Set(results.flatMap((result) => result.cacheTags))];
  for (let i = 0; i < cacheTags.length; i += NEXT_CACHE_TAG_MAX_ITEMS) {
    cacheTag(...cacheTags.slice(i, i + NEXT_CACHE_TAG_MAX_ITEMS));
  }
  // -1: no limit.
  const maxAges = results
    .map((result) => result.cacheMaxAge)
    .filter((maxAge) => maxAge >= 0);
  if (maxAges.length) {
    const maxAge = Math.min(...maxAges);
    cacheLife({ revalidate: maxAge, expire: maxAge });
  }
}

/** Expires every cache entry tagged with one of the tags immediately. */
export function expireCanvasTags(tags: string[]): void {
  for (const tag of tags) {
    // Next.js 15 takes one argument and always expires immediately.
    nextCache.revalidateTag(tag, { expire: 0 });
  }
}
