# Understanding headless cache invalidation

How a change in Drupal expires the pages a headless frontend cached. The decisions
and the alternatives are recorded in
[ADR-0022](../../../docs/adr/0022-announced-cache-tag-invalidation-for-headless-frontends.md).
The settings and operator behavior are in the
[module README](../README.md#frontend-cache-invalidation).

## The flow

```
 editor saves a node
        │
        ▼
 Drupal request invalidates tags            ┌──────────────────────────────┐
 (node:5, config:…, …)                      │ FrontendCacheTagInvalidator  │
        │  cache_tags_invalidator           │  collects tags in memory     │
        └──────────────────────────────────▶│  (>1,000 → http_response)    │
                                            └──────────────┬───────────────┘
 response is sent                                          │ destruct(), last
                                                           ▼
                                  store tags under random ID per URL
                                  (key-value expirable, 5 minutes)
                                                           │
                          POST {"id": "…"}                 ▼
   frontend /api/canvas/revalidate  ◀──────── each URL in cache_invalidation_urls
        │
        │ POST {"id": "…"} /canvas-headless/cache-invalidation
        ▼
   Drupal returns {"tags": […]} once, then forgets the ID
        │
        ▼
   frontend expires everything it cached with those tags
   (Next.js revalidateTag, Astro cache.invalidate, CDN purge, …)
```

The other half is outbound: every content API response carries `cacheTags` and
`cacheMaxAge`, and the frontend stores its cached copy under those tags, for at most
the max-age. Published pages come from `fetchPublishedPage()`, which reads no request
state, so the result is safe to share between visitors.

## Pieces

| Where | What |
|---|---|
| `CanvasContentResponseSubscriber::addCacheTags()` | Adds `cacheTags` and `cacheMaxAge`, including the cache contexts' dependencies, to content API results. |
| `FrontendCacheTagInvalidator` | Collects invalidated tags, announces them after the response. Destructed last. Never throws. |
| `CacheInvalidationController` | Returns the tags for an ID once. |
| `hook_rebuild`, `hook_cron` | Cache rebuild announces `http_response` at once; cron retries URLs that missed an announcement. |
| `createCacheInvalidationHandler()` | Framework-agnostic frontend route body: validates the announcement, reads the tags, calls `invalidate(tags)`. |
| Framework adapters | Connect `invalidate` and the page cache; see below. |

## Failure behavior

| What goes wrong | What happens |
|---|---|
| Frontend unreachable, or answers non-2xx | Logged to the `canvas_headless` channel. The URL is remembered; its next announcement (next invalidating request, or cron) is `http_response`. |
| Frontend answered, then cannot read the tags (deferred read) | Drupal cannot know. The handler invalidates `http_response` itself and logs. |
| Unknown or replayed ID (404) | Nothing is expired. A forged announcement costs one request to Drupal. |
| More than 1,000 tags, router rebuild, `drush cache:rebuild` | `http_response` is announced: everything expires. |
| `auto_save` tag invalidated | Not announced. |

## Frontend support

| Framework | Page cache | What the app does |
|---|---|---|
| Next.js 16 (Cache Components) | `'use cache'` | `cacheCanvasTags(page)`; mount `createCacheInvalidationRouteHandler()`. |
| Astro 7 | Route cache (`cache.provider`) | `cacheCanvasTags(Astro, page)`; the integration injects the route. |
| Nuxt 4 | None with tags | Tag a CDN with `cacheTags`; mount `createCacheInvalidationRouteHandler({ invalidate })`. |
| TanStack Start | None with tags | Same as Nuxt. |
| Angular, React | No server layer | Not applicable; use the framework-agnostic handler from `@drupal-canvas/headless/server`. |
