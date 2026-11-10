import { isDraftModeEnabled } from './server';

import type { Cacheability } from '@drupal-canvas/headless';
import type { AstroDraftContext } from './adapter';

/** The part of Astro's route cache (`Astro.cache`, Astro 7+) used here. */
export interface AstroCacheContext extends AstroDraftContext {
  cache: {
    enabled: boolean;
    set(input: { maxAge?: number; tags?: string[] } | false): void;
    invalidate(input: { tags: string[] }): Promise<void>;
  };
}

/** One year, in seconds: Astro caches nothing without a max-age. */
const NO_LIMIT_MAX_AGE = 31_536_000;

/**
 * Applies the Drupal cacheability of Canvas results to the current response
 * in Astro's route cache: all their cache tags, so that the cache
 * invalidation route expires it when Drupal invalidates any of them, and the
 * shortest max-age (no limit: one year). Call it once per page, after
 * fetching published results with `fetchPublishedPage()`. Draft sessions are
 * never cached. Needs Astro 7 with a `cache.provider` configured; without
 * one it does nothing.
 *
 * ```astro
 * const page = await fetchPublishedPage(Astro, Astro.url.pathname);
 * cacheCanvasTags(Astro, page);
 * ```
 */
export function cacheCanvasTags(
  context: AstroCacheContext,
  ...results: Cacheability[]
): void {
  if (!context.cache.enabled) {
    return;
  }
  if (isDraftModeEnabled(context)) {
    context.cache.set(false);
    return;
  }
  const maxAges = results
    .map((result) => result.cacheMaxAge)
    .filter((maxAge) => maxAge >= 0);
  context.cache.set({
    tags: [...new Set(results.flatMap((result) => result.cacheTags))],
    maxAge: maxAges.length ? Math.min(...maxAges) : NO_LIMIT_MAX_AGE,
  });
}
