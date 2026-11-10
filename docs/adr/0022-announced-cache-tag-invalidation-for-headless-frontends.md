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
- The site-wide frontend list holds frontends of different kinds. Some are production sites that cache pages. Others do not cache, or are local development apps that Drupal's server cannot reach. Drupal cannot know the kind of a frontend in advance.
- The developer who builds the frontend decides whether it caches pages. Administrators manage the frontend list in Drupal, and may not know what the developer decided.
- A frontend that stops receiving announcements keeps serving stale pages, and nothing on the frontend shows it. An administrator needs to see the failure in Drupal.
- A warning about a frontend that never cached pages reports no problem, and hides the warnings that do.

## Decision

**Drupal returns cacheability.** Successful content, entity and redirect responses, and 403 and 404 responses of the content API include `cacheTags` and `cacheMaxAge` (-1: no limit). Both include the cache contexts' own dependencies, so a path-keyed cache sees them.

**Drupal announces tag IDs, not tags.** Drupal collects the tags invalidated during a request. After the response, it stores them in an expirable key-value collection for five minutes, under a random, unguessable ID per frontend. It sends `POST {"id": "..."}` to each frontend. The frontend reads the tags with `POST` to a Drupal route, with the ID in the body, so access logs never record it. The route returns the tags once and forgets them. The announcement is only a hint: a forged one makes the frontend ask Drupal, which answers 404. The ID is the only credential, and the tags always come from Drupal, so no shared secret exists.

**Drupal announces to every frontend in the list, and nothing is configured.** The frontends are the entries of the site-wide frontend list, the list that also serves editor previews. Every entry receives announcements. Cache invalidation has no setting: not per frontend, and not for the site. A frontend takes part by serving the announcement route. The developer makes that decision in the frontend's code, and Drupal asks nobody to repeat it.

**The announcement path is fixed.** Drupal sends announcements to `/api/canvas/revalidate` under the frontend's registered URL. An administrator enters no route URL. The frontend serves the route at that path: an adapter injects it where the framework allows, and otherwise the application mounts the adapter's handler there.

**A frontend is connected once it has read announced tags.** Reading the tags proves that the frontend serves the route and can reach Drupal. The frontend stays connected until it is removed from the list. Drupal reports and logs only connected frontends. A frontend that is not connected is not shown as failing in the frontend list, and is not named in the status report. Its failed announcements are not logged. Such a frontend may not cache pages, may not serve the route, or may be unreachable from Drupal's server.

**A frontend that is not connected, and answers, is asked again without a wait.** When such a frontend answers an announcement with a redirect or an error status, for example 404, Drupal does not back off. It announces to the frontend again at the next request that invalidates tags, and at cron. The frontend therefore connects at the first invalidation after its route exists. A frontend that is not connected and cannot be reached gets the same wait as a connected frontend. So does one that accepts announcements without reading the tags.

**First contact expires everything.** Drupal keeps a state record per frontend. A frontend without a record is treated as having missed everything, so every announcement to it is `http_response` until it reads one. A frontend that is added to the list therefore expires everything at its first read.

**Everything expires when precision is lost.** Drupal announces `http_response`, a tag every Drupal response carries, when a request invalidates more than 1,000 tags, on a router rebuild and on a cache rebuild. It announces the same tag to a frontend that may have missed an invalidation.

**Drupal detects a missed announcement.** An announcement is missed when the frontend cannot be reached, or answers with a redirect or a non-2xx status. It is also missed when the frontend does not read the announced tags within 30 seconds. Drupal checks for unread IDs at the frontend's next announcement and at cron. After a miss, the frontend's next announcement, from the next invalidating request or from cron, is `http_response`. The miss is cleared only when the frontend reads an `http_response` announcement that was made after it.

**An unauthenticated announcement never expires everything.** A frontend that cannot read the tags expires nothing, because the announcement may be forged and Drupal may be unreachable. Drupal sees the unread ID and announces `http_response` later. One case remains on the frontend: it read the tags, had already answered, and then failed to expire them. Drupal counts those tags as delivered, so the frontend expires everything itself.

**Drupal backs off from a failing frontend.** After a failure, Drupal does not contact the frontend for 10 seconds. The wait doubles with each consecutive failure, up to five minutes. For an unread announcement, the wait starts when the read timed out, not when Drupal notices it. A miss noticed after the wait is therefore repaired by the announcement that notices it. Tags invalidated during the wait are not announced, because the recorded miss covers them. The wait ends when the frontend reads an announcement. The one exception is the frontend that is not connected and answers, as described above.

**A connected frontend has a status.** The status is ok or failing. It is failing from a miss, or from an announcement unread for 30 seconds, until the miss is cleared. It includes the last error, the time of the last failure, and the time of the last read. A frontend that is not connected has no status.

**The frontend list shows one status per frontend, with a retry.** The list shows each frontend's connection state, as checked by the browser, in one place. Cache invalidation adds no second indicator and no control of its own. For a connected frontend whose status is ok, the ready indicator also confirms that cached pages expire when content changes. It gives the time of the frontend's last read. For a connected frontend whose status is failing, a retry action replaces the ready indicator. It states that the frontend is not receiving content updates, the last error, and the time of the failure. A frontend that the browser cannot reach, or that has no adapter, shows that state.

**The retry is one announcement, made at once.** Drupal announces to one frontend of the list on request, and ignores the wait after a failure. It waits up to five seconds for the frontend to read the tags. It announces `http_response` when a miss is on record, and otherwise a tag that no response carries, so nothing expires. The result states which step failed: the request to the frontend, or the read from Drupal. An announcement the frontend does not read in that time is recorded as a failure at once. The list offers no action that expires everything: a cache rebuild does that.

**The status report warns.** The site's status report shows a warning while a connected frontend is failing. The warning lists each failing frontend with its last error and the time of the failure. The status is given only to users who administer the frontend list. Users who can only preview do not get it, because errors can name hosts of the site's network.

**Tags that never reach published responses are not announced.** The auto-save tag is invalidated on every editor auto-save and only appears in preview responses, so announcing it would cost every frontend a round trip for nothing.

**Announcing never throws.** The invalidator is destructed last, to include invalidations other destructors make, and logs every exception instead of throwing, so later destructors still run. Redirects are not followed and requests time out, so a slow frontend cannot hold a PHP worker for long. The state record changes under a lock, which is not held during the request to the frontend.

**The frontend side is framework-agnostic at the core.** One handler receives the announcement, reads the tags and calls an `invalidate(tags)` callback; adapters connect the callback to their cache. Frameworks with a tag cache use it (Next.js `revalidateTag()`, Astro's route cache `invalidate()`). Others pass the tags to the application, for example a CDN purge. The handler may answer before reading the tags where the framework allows running work after the response. The read from Drupal times out after ten seconds.

## Consequences

- No secret to configure, rotate or leak. A leaked ID is useful for at most five minutes, once, and reveals only tag names.
- Nothing is set up in Drupal. A frontend that serves the route, and is in the list, connects at the first invalidation and receives announcements from then on.
- Pages expire when what they depend on changes, including permissions and routing. Every content API response depends on `route_match` and `config:core.extension`, so saving a path alias or rebuilding the router expires every cached page.
- A replayed ID is harmless. A forged announcement costs the frontend one request to Drupal and expires nothing, also when the frontend answers before reading the tags.
- The frontend list has two uses. Its first entry serves editor previews, and every entry receives announcements. A production frontend has to be in the list to be told.
- Announcements cannot be stopped for a frontend that stays in the list. A frontend that is not connected and answers receives one request for each request that invalidates tags, and one for each cron run.
- A frontend that never connected is never reported. A route that never worked, for example at the wrong path or with the wrong Drupal URL, shows nothing in Drupal. The developer confirms a new route by changing content and looking for the confirmation on the frontend list.
- A connected frontend that stops serving the route on purpose is reported as failing. Removing it from the list and adding it again deletes its state: it is then not connected.
- A frontend cannot receive announcements at another path, or on another host, than its registered URL. A frontend served under a path prefix is registered with the prefix.
- Drupal stores state per frontend. Removing a frontend from the list deletes the state, however the list is saved.
- A missed invalidation is repaired by the next invalidating request or by cron once the wait after the miss has passed, not earlier. The frontend serves stale pages until then. A frontend that answers before reading, and then cannot read, is detected after 30 seconds at the earliest.
- A frontend that recovers from a failure always expires everything once, because the tags it missed are not kept. So does a frontend at its first read.
- Expiring everything by hand takes a cache rebuild, which also empties Drupal's own caches and reaches every frontend.
- Reading the tags removes them. If a frontend answered the announcement first, and the answer to its read is then lost, neither side records a miss, and those tags expire only when they are invalidated again.
- While a frontend is down, Drupal sends it at most one announcement per wait. Other invalidating requests do not wait for it.
- A frontend that does not defer the read holds the announcing PHP worker for the duration of the read. `drush cache:rebuild` waits for the announcements, which are sent in parallel, up to the request timeout.
- Announcements happen once per request, at the end, so a long drush command or migration announces when it finishes, usually `http_response`.
- A reverse proxy or CDN in front of the content API can serve a stale copy to a frontend refetching before the proxy purges. The content API has to be excluded from that cache, or purged first.
- The retry takes the same path as an announcement. Drupal's server has to reach the frontend, and the frontend's server has to reach Drupal, for the retry to pass.
- Tag length limits of the frontend's cache apply; Next.js drops tags longer than 256 characters.
