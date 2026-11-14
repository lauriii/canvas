# ADR 22: Announced cache tag invalidation for headless frontends

<!-- cspell:words purge purges -->

Date: 2026-11-10

## Status

Accepted

## Context

A headless frontend caches rendered pages by path. Drupal knows when the content behind a path changes, through cache tag invalidation, but the frontend has no way to learn it. Without a signal, a frontend either serves stale pages or does not cache.

Constraints:

- A path-keyed frontend cache ignores Drupal's cache contexts. A change to what a context depends on, such as the anonymous role's permissions, must still expire the pages that varied by it.
- Frontends differ: some have a tag cache (Next.js, Astro), others only a CDN purge API or nothing.
- Drupal must not trust unauthenticated callers, and the frontend must not trust unauthenticated announcements. Sharing a secret between Drupal and each frontend adds setup and rotation work.
- A cache tag invalidation can happen anywhere: a web request, drush, cron, a deferred router rebuild, a cache rebuild. Failing to notify must never break the request that invalidated.
- A frontend that receives no announcements keeps serving stale pages, and nothing on the frontend shows it. An administrator needs to see the failure in Drupal.

## Decision

**Drupal returns cacheability.** Successful content, entity and redirect responses, and client errors (4xx) of the content API include `cacheTags` and `cacheMaxAge` (-1: no limit). Both include the cache contexts' own dependencies, so a path-keyed cache sees them.

**Drupal announces tag IDs, not tags.** Drupal collects the tags invalidated during a request. After the response, it stores them in an expirable key-value collection under a random, unguessable ID, for five minutes, and sends `POST {"id": "..."}` to each frontend that has cache invalidation turned on. The frontend reads the tags with `POST` to a Drupal route, with the ID in the body, so access logs never record it. The route returns the tags once and forgets them. The announcement is only a hint: a forged one makes the frontend ask Drupal, which answers 404. The ID is the only credential, and the tags always come from Drupal, so no shared secret exists.

**One frontend list, with a switch per frontend.** Cache invalidation is a boolean on each entry of the site-wide frontend list, the list that also serves editor previews. The switch is off by default, and Drupal never announces to a frontend whose switch is off; an administrator can still test such a frontend. No second list of URLs exists: a frontend is registered once and managed on one screen.

**The announcement path is fixed.** Drupal sends announcements to `/api/canvas/revalidate` under the frontend's registered URL. An administrator enters no route URL. The frontend serves the route at that path: an adapter injects it where the framework allows, and otherwise the application mounts the adapter's handler there.

**Everything expires when precision is lost.** Drupal announces `http_response`, a tag every Drupal response carries, when a request invalidates more than 1,000 tags, on a router rebuild and on a cache rebuild. It announces the same tag to a frontend that may have missed an invalidation.

**Drupal detects a missed announcement.** Drupal keeps a state record per frontend. An announcement is missed when the frontend cannot be reached, or answers with a redirect or a non-2xx status. It is also missed when the frontend does not read the announced tags within 30 seconds. Drupal checks for unread IDs at the frontend's next announcement and at cron. After a miss, the frontend's next announcement, from the next invalidating request or from cron, is `http_response`. The miss is cleared only when the frontend reads an `http_response` announcement that was made after it. A frontend without a state record is treated as having missed everything, so turning its switch on, which deletes the record, makes its first announcement `http_response`: what changed while the switch was off expires.

**An unauthenticated announcement never expires everything.** A frontend that cannot read the tags expires nothing, because the announcement may be forged and Drupal may be unreachable. Drupal sees the unread ID and announces `http_response` later. One case remains on the frontend: it read the tags, had already answered, and then failed to expire them. Drupal counts those tags as delivered, so the frontend expires everything itself.

**Drupal backs off from a failing frontend.** After a failure, Drupal does not contact the frontend for 10 seconds. The wait doubles with each consecutive failure, up to five minutes. For an unread announcement, the wait starts when the read timed out, not when Drupal notices it, so a miss noticed after the wait is repaired by the announcement that notices it. Tags invalidated during the wait are not announced, because the recorded miss covers them. The wait ends when the frontend reads an announcement.

**An administrator can test the round trip.** Drupal announces to one frontend on request, and waits up to five seconds for the frontend to read the tags. The test announces a tag that no response carries, so nothing expires. It announces `http_response` when the administrator asks to expire everything, or when a miss is recorded. The result states which step failed: the request to the frontend, or the read from Drupal. The test ignores the wait after a failure. It runs once when the switch is turned on. A test the frontend does not read is recorded as a failure at once.

**The status is visible.** Each frontend with the switch on has a status: idle (it has read nothing yet), ok, or failing. The status includes the last error, the time of the last failure, and the time of the last read. The frontend list shows the status to administrators of the list, and the site's status report warns while a frontend is failing. Users who can only preview do not get the status, because errors can name hosts of the site's network.

**Tags that never reach published responses are not announced.** The auto-save tag is invalidated on every editor auto-save and only appears in preview responses, so announcing it would cost every frontend a round trip for nothing.

**Announcing never throws.** The invalidator is destructed last, to include invalidations other destructors make, and logs every failure instead of throwing, so later destructors still run. Redirects are not followed and requests time out, so a slow frontend cannot hold a PHP worker for long. The state record changes under a lock, which is not held during the request to the frontend.

**The frontend side is framework-agnostic at the core.** One handler receives the announcement, reads the tags and calls an `invalidate(tags)` callback; adapters connect the callback to their cache. Frameworks with a tag cache use it (Next.js `revalidateTag()`, Astro's route cache `invalidate()`). Others pass the tags to the application, for example a CDN purge. The handler may answer before reading the tags where the framework allows running work after the response. The read from Drupal times out after ten seconds.

## Consequences

- No secret to configure, rotate or leak. A leaked ID is useful for at most five minutes, once, and reveals only tag names.
- Pages expire when what they depend on changes, including permissions and routing. Every content API response depends on `route_match` and `config:core.extension`, so saving a path alias or rebuilding the router expires every cached page.
- A replayed ID is harmless. A forged announcement costs the frontend one request to Drupal and expires nothing, also when the frontend answers before reading the tags.
- The frontend list has two uses. Its first entry serves editor previews, and every entry with the switch on receives announcements. A production frontend has to be in the list, with the switch on, to be told.
- A frontend cannot receive announcements at another path, or on another host, than its registered URL. A frontend served under a path prefix is registered with the prefix.
- Drupal stores state per frontend. Removing a frontend, or turning its switch off, deletes the state.
- A missed invalidation is repaired by the next invalidating request or by cron once the wait after the miss has passed, not earlier. The frontend serves stale pages until then. A frontend that answers before reading, and then cannot read, is detected after 30 seconds at the earliest.
- A frontend that recovers from a failure always expires everything once, because the tags it missed are not kept. So does a frontend whose switch is turned on.
- Reading the tags removes them. If a frontend answered the announcement first, and the answer to its read is then lost, neither side records a miss, and those tags expire only when they are invalidated again.
- While a frontend is down, Drupal sends it at most one announcement per wait. Other invalidating requests do not wait for it.
- A frontend that does not defer the read holds the announcing PHP worker for the duration of the read. `drush cache:rebuild` waits for the announcements, which are sent in parallel, up to the request timeout.
- Announcements happen once per request, at the end, so a long drush command or migration announces when it finishes, usually `http_response`.
- A reverse proxy or CDN in front of the content API can serve a stale copy to a frontend refetching before the proxy purges. The content API has to be excluded from that cache, or purged first.
- The test takes the same path as an announcement. Drupal's server has to reach the frontend, and the frontend's server has to reach Drupal, for the test to pass.
- Tag length limits of the frontend's cache apply; Next.js drops tags longer than 256 characters.
