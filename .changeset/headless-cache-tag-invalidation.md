---
'@drupal-canvas/headless': minor
'@drupal-canvas/headless-next': minor
---

Add cache tag invalidation for published content.

- Page, redirect, and entity results include `cacheTags`, the Drupal cache tags they depend on. The `Page`, `PageRedirect`, and `EntityResult` types now require the field; add `cacheTags: []` to objects you construct.
- `fetchPublishedPage(path)` fetches a page without reading the current request, for caches keyed by path. It throws for errors other than 403/404.
- `createCacheInvalidationHandler()` handles Drupal's invalidation announcements at `/api/canvas/revalidate`, reads the invalidated tags from Drupal, and passes them to an `invalidate` callback. It needs no secret or configuration.
- Next.js: `cacheCanvasTags(page)` tags a `'use cache'` entry (a `null` page expires when content changes), and the `revalidate` route handler from `createDraftRouteHandlers()` expires tagged entries with `revalidateTag()`.
