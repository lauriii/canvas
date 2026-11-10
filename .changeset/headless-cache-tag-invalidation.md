---
'@drupal-canvas/headless': minor
'@drupal-canvas/headless-next': minor
---

Add cache tag invalidation for published content.

- Page, redirect, and entity results include `cacheTags` and `cacheMaxAge`, their Drupal cacheability (`Cacheability`). The `Page`, `PageRedirect`, and `EntityResult` types now require both fields; add `cacheTags: [], cacheMaxAge: -1` to objects you construct.
- `fetchPublishedPage(path)` fetches a page without reading the current request, for caches keyed by path. A 403/404 is a `PageNotFound` with the error's cacheability, recognized by `isPageNotFound()`; other errors throw.
- `createCacheInvalidationHandler()` handles Drupal's invalidation announcements, reads the invalidated tags from Drupal once, and passes them to an `invalidate` callback. It needs no secret. Mount it at `/api/canvas/revalidate` under the frontend URL registered in Canvas. Nothing is turned on in Drupal: it announces to that path under every frontend registered in Canvas. With `defer`, it answers before reading the tags; a read that fails then expires nothing, because Drupal detects unread tags and later announces `http_response`. The read times out after ten seconds.
- Next.js (16, with Cache Components): `cacheCanvasTags(...results)` tags a `'use cache'` entry and limits it to the shortest Drupal max-age, and `createCacheInvalidationRouteHandler()` answers at once and expires tagged entries with `revalidateTag()` in `after()`.
