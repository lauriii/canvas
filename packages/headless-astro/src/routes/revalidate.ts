import { createCacheInvalidationHandler } from '@drupal-canvas/headless/server';

import type { APIRoute } from 'astro';
import type { AstroCacheContext } from '../cache';

export const prerender = false;

/**
 * Cache invalidation: Drupal POSTs `{id}`, and the tags read back from Drupal
 * are purged from Astro's route cache. Injected at /api/canvas/revalidate by
 * the canvas() integration, the path Drupal announces to once cache
 * invalidation is turned on for the frontend in Canvas.
 */
export const POST: APIRoute = (context) =>
  createCacheInvalidationHandler({
    // Astro has no after(): Drupal waits for the read, about one request.
    invalidate: (tags) =>
      (context as unknown as AstroCacheContext).cache.invalidate({ tags }),
  })(context.request);
