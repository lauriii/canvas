# Understanding headless cache invalidation

How a change in Drupal expires the pages a headless frontend cached. The decisions
and their consequences are recorded in
[ADR-0022](../../../docs/adr/0022-announced-cache-tag-invalidation-for-headless-frontends.md).
What a developer does and what an administrator sees are in the
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
                            for each frontend in the frontend list:
                            ┌──────────────────────────────────────────────┐
                            │ an ID unread for 30 s?  → record a miss      │
                            │ waiting after a failure? → record a miss,    │
                            │                            do not contact    │
                            │ a miss on record, or    → announce           │
                            │ no record yet?            http_response      │
                            └──────────────┬───────────────────────────────┘
                                           ▼
                            store tags under a random ID (key-value
                            expirable, 5 minutes); note the ID as pending
                                           │
              POST {"id": "…"}             ▼
   <frontend URL>/api/canvas/revalidate ◀──┘
        │                          no answer, redirect or non-2xx:
        │                          record a miss, wait 10 s … 5 minutes
        │                          (not connected, and it answered: no wait)
        │
        │ POST {"id": "…"} /canvas-headless/cache-invalidation
        ▼
   Drupal returns {"tags": […]} once, forgets the ID,
   and records that the frontend read it: the frontend is connected
        │
        ▼
   frontend expires everything it cached with those tags
   (Next.js revalidateTag, Astro cache.invalidate, CDN purge, …)
```

Nothing is configured. Drupal announces to every frontend in the list, at the fixed
path `/api/canvas/revalidate` under the frontend's registered URL.

A frontend is _connected_ once it has read announced cache tags at least once. Only a
connected frontend is reported or logged. A frontend that never read an announcement
may not cache pages, may not serve the route, or may be a local app Drupal cannot
reach.

The other half is outbound: every content API response carries `cacheTags` and
`cacheMaxAge`, and the frontend stores its cached copy under those tags, for at most
the max-age. Published pages come from `fetchPublishedPage()`, which reads no request
state, so the result is safe to share between visitors.

## Pieces

| Where | What |
|---|---|
| `CanvasContentResponseSubscriber::addCacheTags()` | Adds `cacheTags` and `cacheMaxAge`, including the cache contexts' dependencies, to content API results. |
| `canvas_headless.settings:frontends` | The frontend list. Every entry receives announcements. An entry has no cache invalidation setting. |
| `FrontendCacheTagInvalidator` | Collects invalidated tags, announces them after the response to `/api/canvas/revalidate` under each frontend URL. Keeps the state per frontend. Destructed last. Never throws. |
| `FrontendCacheTagInvalidator::redeem()`, `CacheInvalidationController` | Return the tags for an ID once, and record that the frontend read them. |
| `FrontendCacheTagInvalidator::getStatus()`, `test()` | The status of each connected frontend, and the round trip behind the **Not receiving content updates** button. |
| `GET` and `PATCH /canvas/api/v0/headless/frontends` | Return the list. Each item has `cacheInvalidationStatus`: the status of a connected frontend, or `null`. `PATCH` saves the list. |
| `POST /canvas/api/v0/headless/frontends/cache-invalidation/test` | Announces to one frontend in the list now and returns the result message. |
| `CanvasHeadlessRequirements` | Adds a status report warning while a connected frontend is failing. |
| `CacheInvalidationConfigSubscriber`, `hook_uninstall` | Delete the state of a frontend removed from the list when the settings are saved, and all state on uninstall. |
| `hook_rebuild`, `hook_cron` | Cache rebuild announces `http_response` at once; cron announces `http_response` to frontends that missed an announcement. |
| `createCacheInvalidationHandler()` | Framework-agnostic frontend route body: validates the announcement, reads the tags, calls `invalidate(tags)`. |
| Framework adapters | Connect `invalidate` and the page cache; see below. |

## What administrators see

The **Headless frontends** screen in Canvas has no control for cache invalidation.
Each frontend's row shows one status. For users with the
`administer canvas headless frontends` permission, cache invalidation changes that
status in two cases:

| The frontend is | `cacheInvalidationStatus` | The row shows |
|---|---|---|
| connected, and reads its announcements | `state: ok` | The **Ready** badge, with the tooltip `Cached pages expire when content changes. Last update received <time>.` |
| connected, with a miss on record or an announcement unread for 30 seconds | `state: failing` | The button **Not receiving content updates** in place of the badge. The tooltip reads `This frontend stopped receiving content updates, so it may show outdated pages. Canvas keeps trying, and tells it to expire its cached pages once it responds. Click to try now.`, followed by the time of the last failure and the last error. |

- **Not connected.** `cacheInvalidationStatus` is `null`. The row shows the usual
  badge, and nothing about cache invalidation.
- **The browser's own check.** The browser checks each frontend for the adapter. The
  button is shown when that check finds the frontend ready. While the check runs,
  the row shows **Checking…**. When the check fails, the row shows **Unreachable**
  or **Setup needed**. The screen reads the status again each time it is opened or
  returned to, and every ten seconds while it is in view.
- **The button.** A click calls the test route. Drupal announces to the frontend at
  once, also during the wait after a failure, and waits up to five seconds for the
  read. A failing frontend has a miss on record, so the announced tag is
  `http_response`. The result is shown as a message, and the list is fetched again.
  A test the frontend does not read is recorded as a failure at once.
- **Expire cached pages.** The menu at the end of a connected frontend's row has this
  action, next to **Remove**. It calls the test route with `expireAll`, so the
  announced tag is `http_response`. The message says that the frontend was told to
  expire its cached pages: Drupal knows that the frontend read the tags, not what
  the frontend did with them.
- **The test route.** It accepts every frontend in the list. To a frontend without a
  miss on record, it announces `canvas_headless:test`, which no response carries,
  unless `expireAll` is set.
- **The status report.** The site's status report shows a warning while a connected
  frontend is failing, with each failing frontend's URL, last error and time.
- **The log.** A failed request to a connected frontend, and an announcement it
  did not read, are logged to the `canvas_headless` channel.

The status holds `state`, `lastSuccess`, `lastFailure` and `lastError`. Users who can
only preview get the frontend list with `null` for every status: errors can name hosts
of the site's network.

To expire every frontend at once, an administrator rebuilds Drupal's caches, which
announces `http_response`. The messages and their causes are listed in the
[module README](../README.md#troubleshooting).

## State per frontend

Drupal keeps one record for each frontend in the list, from the first announcement to
it. The record is stored in the key-value collection
`canvas_headless.cache_invalidation_state`. Its key is a hash of the frontend's
canonical URL, because a URL can be longer than a key. It holds the URL and:

- whether the frontend may have missed an invalidation, and since when;
- the IDs that were announced and not read yet, with the time of each;
- the number of failures in a row;
- the last error, and the time of the last failure;
- the time the frontend last read announced tags: the frontend is connected once this
  is set;
- the time before which Drupal does not contact the frontend.

How the record changes:

- A frontend without a record may have missed anything. Its record starts with a
  miss, so its first announcement is `http_response`. This covers a frontend that was
  just added to the list.
- An announcement adds its ID to the unread IDs before the request is sent.
- A failed request removes the ID, records a miss and the error, and sets the wait:
  10 seconds, doubling with each failure in a row, up to five minutes.
- A frontend that is not connected, and answered with an HTTP status, gets no wait.
  The next request that invalidates tags announces to it again.
- An ID that stays unread for 30 seconds counts as a failure. Drupal checks this at
  the next announcement and at cron. The wait starts when the 30 seconds ran out,
  so a miss noticed after the wait is repaired by the announcement that notices it.
- A test that is not read within five seconds is a failure at once, and its ID is
  removed.
- A read removes the ID, records the time, and resets the failures and the wait.
- A read of `http_response` also clears the miss, but only when that announcement
  was made after the miss.
- Removing the frontend from the list, or uninstalling the module, deletes the
  record. This happens whenever the settings are saved with a changed list: by the
  Canvas UI, a config import, or drush. Reading tags announced before that does not
  create a new record. A frontend that is added again starts without one.

Each change happens under a lock. The lock is not held during the request to the
frontend. The announced tags are stored apart from the record, in an expirable
collection, for five minutes.

## Failure behavior

| What goes wrong | What happens |
|---|---|
| A frontend that is not connected answers without the route, for example with 404 | Not logged and not reported. A miss is recorded, without a wait. Every request that invalidates tags, and every cron run, announces `http_response` to it until it reads one. |
| A frontend that is not connected cannot be reached | Not logged and not reported. A miss is recorded, and Drupal waits 10 seconds, then twice as long after each failure, up to five minutes. |
| A connected frontend is unreachable, or answers with a redirect or non-2xx | Logged to the `canvas_headless` channel. The status is `failing`. A miss is recorded; the frontend's next announcement (next invalidating request, or cron) is `http_response`. |
| Frontend answered 2xx, then cannot read the tags (deferred read) | The handler logs the failure and expires nothing. The status of a connected frontend is `failing` once the ID is unread for 30 seconds. At the next announcement, cron run or retry, Drupal records the miss and logs it. It announces `http_response` once the wait after the miss has passed. |
| Frontend read the tags, but the answer was lost (deferred read) | The ID is gone and nobody records a miss. The tags expire only when they are invalidated again. |
| One frontend's record cannot be saved | Logged. The other frontends are still announced to. |
| Tags were read, then `invalidate` throws | Without `defer`, the route answers 500 and Drupal records a miss. With `defer`, the handler logs and invalidates `http_response` itself. |
| Frontend keeps failing | Drupal waits 10 seconds, then twice as long after each failure, up to five minutes. Tags invalidated during a wait are not announced; the miss on record covers them. |
| Unknown or replayed ID (404) | Nothing is expired. A forged announcement costs one request to Drupal, and never expires everything. |
| More than 1,000 tags, router rebuild, `drush cache:rebuild` | `http_response` is announced: everything expires. |
| `auto_save` tag invalidated | Not announced. |

## Frontend support

The route is always `POST /api/canvas/revalidate` under the frontend URL registered
in Canvas. Nothing is turned on in Drupal for any framework: the frontend connects
when it reads its first announcement.

| Framework | Page cache | What the app does | Answers before reading the tags |
|---|---|---|---|
| Next.js 16 (Cache Components) | `'use cache'` | `cacheCanvasTags(page)`; mount `createCacheInvalidationRouteHandler()` at `app/api/canvas/revalidate/route.ts`. | Yes, with `after()`. |
| Astro 7 | Route cache (`cache.provider`) | `cacheCanvasTags(Astro, page)`; the integration injects the route. | No. |
| Nuxt 4 | None with tags | Tag a CDN with `cacheTags`; mount `createCacheInvalidationRouteHandler({ invalidate })` at `server/api/canvas/revalidate.post.ts`. | When the Nitro preset provides `event.waitUntil`. |
| TanStack Start | None with tags | Tag a CDN with `cacheTags`; mount `createCacheInvalidationRouteHandler({ invalidate })` at `src/routes/api/canvas.revalidate.ts`. | When the app passes `defer`. |
| Angular, React | No server layer | Not applicable; use the framework-agnostic handler from `@drupal-canvas/headless/server`. | When the app passes `defer`. |
