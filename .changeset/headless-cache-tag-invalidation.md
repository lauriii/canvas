---
'@drupal-canvas/headless': minor
'@drupal-canvas/headless-next': minor
---

Add cache tag invalidation for published content.

- Page, redirect, and entity results include `cacheTags` and `cacheMaxAge`, their Drupal cacheability (`Cacheability`). The `Page`, `PageRedirect`, and `EntityResult` types now require both fields; add `cacheTags: [], cacheMaxAge: -1` to objects you construct.
- `fetchPublishedPage(path)` fetches a page without reading the current request, for caches keyed by path. A 403/404 is a `PageNotFound` with the error's cacheability; other errors throw.
- `createCacheInvalidationHandler()` handles Drupal's invalidation announcements at `/api/canvas/revalidate`, reads the invalidated tags from Drupal, and passes them to an `invalidate` callback. It needs no secret or configuration. With `defer`, it answers before reading the tags.
- Next.js: `cacheCanvasTags(page)` tags a `'use cache'` entry and limits it to the Drupal max-age, and the `revalidate` route handler from `createDraftRouteHandlers()` answers at once and expires tagged entries with `revalidateTag()` in `after()`.
