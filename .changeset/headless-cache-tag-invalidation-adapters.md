---
'@drupal-canvas/headless-astro': minor
'@drupal-canvas/headless-nuxt': minor
'@drupal-canvas/headless-tanstack-start': minor
---

Add cache tag invalidation for published content.

- Astro (7, with a `cache.provider`): `fetchPublishedPage()` and `cacheCanvasTags()` tag the response in Astro's route cache with the page's Drupal cache tags and max-age, and the integration injects `/api/canvas/revalidate`, which purges the tagged entries when Drupal announces invalidated tags.
- Nuxt and TanStack Start have no tag cache: `fetchPublishedPage()` and `createCacheInvalidationRouteHandler({ invalidate })` pass the invalidated tags to the app, for example a CDN purge. Mount the route at `/api/canvas/revalidate`.
- Drupal announces to `/api/canvas/revalidate` under every frontend registered in Canvas. Nothing is turned on in Drupal. To confirm, change content, or hover **Ready** on the Canvas Headless frontends screen.
