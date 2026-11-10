---
'@drupal-canvas/headless-astro': minor
'@drupal-canvas/headless-nuxt': minor
'@drupal-canvas/headless-tanstack-start': minor
---

Add cache tag invalidation for published content.

- Astro (7, with a `cache.provider`): `fetchPublishedPage()` and `cacheCanvasTags()` tag the response in Astro's route cache with the page's Drupal cache tags and max-age, and the integration injects `/api/canvas/revalidate`, which purges the tagged entries when Drupal announces invalidated tags.
- Nuxt and TanStack Start have no tag cache: `fetchPublishedPage()` and `createCacheInvalidationRouteHandler({ invalidate })` pass the invalidated tags to the app, for example a CDN purge.
