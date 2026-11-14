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
                            for each frontend with cache invalidation on:
                            ┌──────────────────────────────────────────────┐
                            │ an ID unread for 30 s?  → record a miss      │
                            │ waiting after a failure? → record a miss,    │
                            │                            do not contact    │
                            │ a miss on record?       → announce           │
                            │                            http_response     │
                            └──────────────┬───────────────────────────────┘
                                           ▼
                            store tags under a random ID (key-value
                            expirable, 5 minutes); note the ID as pending
                                           │
              POST {"id": "…"}             ▼
   <frontend URL>/api/canvas/revalidate ◀──┘
        │                          no answer, redirect or non-2xx:
        │                          record a miss, wait 10 s … 5 minutes
        │
        │ POST {"id": "…"} /canvas-headless/cache-invalidation
        ▼
   Drupal returns {"tags": […]} once, forgets the ID,
   and records that the frontend read it
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
| `canvas_headless.settings:frontends` | The frontend list. `cache_invalidation` on an entry turns announcements on for that frontend. |
| `FrontendCacheTagInvalidator` | Collects invalidated tags, announces them after the response to `/api/canvas/revalidate` under each enabled frontend URL. Keeps the state per frontend. Destructed last. Never throws. |
| `FrontendCacheTagInvalidator::redeem()`, `CacheInvalidationController` | Return the tags for an ID once, and record that the frontend read them. |
| `FrontendCacheTagInvalidator::getStatus()`, `test()` | The status per frontend, and the round-trip test behind **Test** and **Expire all**. |
| `GET` and `PATCH /canvas/api/v0/headless/frontends` | Read and save the switch; return the status of each enabled frontend. |
| `POST /canvas/api/v0/headless/frontends/cache-invalidation/test` | Runs the test for one configured frontend and returns the result message. |
| `CanvasHeadlessRequirements` | Adds a status report warning while a frontend is failing. |
| `hook_rebuild`, `hook_cron` | Cache rebuild announces `http_response` at once; cron announces `http_response` to frontends that missed an announcement. |
| `createCacheInvalidationHandler()` | Framework-agnostic frontend route body: validates the announcement, reads the tags, calls `invalidate(tags)`. |
| Framework adapters | Connect `invalidate` and the page cache; see below. |

## What administrators see

Cache invalidation is managed on the **Headless frontends** screen in Canvas, by
users with the `administer canvas headless frontends` permission.

- **The switch.** Each frontend has a **Cache invalidation** switch, off by default.
  Turning it on saves the list and runs the test once. Tags invalidated while the
  switch was off were not announced, so that first announcement is
  `http_response`: the frontend expires everything once.
- **The status.** An enabled frontend shows one of three statuses:

  | Status | Label | Meaning |
  |---|---|---|
  | `idle` | Waiting for changes | The frontend has not read an announcement yet. |
  | `ok` | Active | The frontend read its last announcement. The tooltip shows when. |
  | `failing` | Failing | An announcement failed or was not read, and the frontend has not read an `http_response` announcement made after that. The tooltip shows the last error and its time. |

- **Test.** Announces the tag `canvas_headless:test`, which no response carries, and
  waits up to five seconds for the frontend to read it. A frontend with a miss on
  record is sent `http_response` instead. The result is shown as a message. A test
  the frontend does not read is recorded as a failure at once.
- **Expire all.** Runs the same round trip with `http_response`, so the frontend
  expires everything it cached.
- **The status report.** The site's status report shows a warning while a frontend
  is failing, with each failing frontend's URL, last error and time.

Users who can only preview get the frontend list without the status: errors can
name hosts of the site's network.

Test and Expire all contact the frontend at once, also during the wait after a
failure. The messages and their causes are listed in the
[module README](../README.md#troubleshooting).

## State per frontend

Drupal keeps one record for each frontend that has the switch on. The record is
stored in the key-value collection `canvas_headless.cache_invalidation_state`, under
a hash of the frontend's canonical URL, because a URL can be longer than a key. It
holds the URL and:

- whether the frontend may have missed an invalidation, and since when;
- the IDs that were announced and not read yet, with the time of each;
- the number of failures in a row;
- the last error, and the time of the last failure;
- the time the frontend last read announced tags;
- the time before which Drupal does not contact the frontend.

How the record changes:

- An announcement adds its ID to the unread IDs before the request is sent.
- A failed request removes the ID, records a miss and the error, and sets the wait:
  10 seconds, doubling with each failure in a row, up to five minutes.
- An ID that stays unread for 30 seconds counts as a failure. Drupal checks this at
  the next announcement and at cron. The wait starts when the 30 seconds ran out,
  so a miss noticed after the wait is repaired by the announcement that notices it.
- A test that is not read within five seconds is a failure at once, and its ID is
  removed.
- A read removes the ID, records the time, and resets the failures and the wait.
- A read of `http_response` also clears the miss, but only when that announcement
  was made after the miss.
- A frontend without a record may have missed anything: its first announcement is
  `http_response`. Turning the switch on deletes the record for that reason, so
  what changed while the switch was off expires.
- Removing the frontend, turning its switch off, or uninstalling the module deletes
  the record. This happens whenever the settings are saved, also by a config
  import. Reading tags announced before that does not create a new one.

Each change happens under a lock. The lock is not held during the request to the
frontend. The announced tags are stored apart from the record, in an expirable
collection, for five minutes.

## Failure behavior

| What goes wrong | What happens |
|---|---|
| Frontend unreachable, or answers with a redirect or non-2xx | Logged to the `canvas_headless` channel. A miss is recorded; the frontend's next announcement (next invalidating request, or cron) is `http_response`. |
| Frontend answered 2xx, then cannot read the tags (deferred read) | The handler logs the failure and expires nothing. Drupal sees an ID that is unread for 30 seconds at the next announcement or cron, records a miss, and announces `http_response` once the wait after the miss has passed. |
| Frontend read the tags, but the answer was lost (deferred read) | The ID is gone and nobody records a miss. The tags expire only when they are invalidated again. |
| One frontend's record cannot be saved | Logged. The other frontends are still announced to. |
| Tags were read, then `invalidate` throws | Without `defer`, the route answers 500 and Drupal records a miss. With `defer`, the handler logs and invalidates `http_response` itself. |
| Frontend keeps failing | Drupal waits 10 seconds, then twice as long after each failure, up to five minutes. Tags invalidated during a wait are not announced; the miss on record covers them. |
| Unknown or replayed ID (404) | Nothing is expired. A forged announcement costs one request to Drupal, and never expires everything. |
| More than 1,000 tags, router rebuild, `drush cache:rebuild` | `http_response` is announced: everything expires. |
| `auto_save` tag invalidated | Not announced. |

## Frontend support

The route is always `POST /api/canvas/revalidate` under the frontend URL registered
in Canvas.

| Framework | Page cache | What the app does | Answers before reading the tags |
|---|---|---|---|
| Next.js 16 (Cache Components) | `'use cache'` | `cacheCanvasTags(page)`; mount `createCacheInvalidationRouteHandler()`. | Yes, with `after()`. |
| Astro 7 | Route cache (`cache.provider`) | `cacheCanvasTags(Astro, page)`; the integration injects the route. | No. |
| Nuxt 4 | None with tags | Tag a CDN with `cacheTags`; mount `createCacheInvalidationRouteHandler({ invalidate })`. | When the Nitro preset provides `event.waitUntil`. |
| TanStack Start | None with tags | Same as Nuxt. | When the app passes `defer`. |
| Angular, React | No server layer | Not applicable; use the framework-agnostic handler from `@drupal-canvas/headless/server`. | When the app passes `defer`. |
