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

## Decision

**Drupal returns cacheability.** Successful content, entity and redirect responses, and client errors (4xx) of the content API include `cacheTags` and `cacheMaxAge` (-1: no limit). Both include the cache contexts' own dependencies, so a path-keyed cache sees them.

**Drupal announces tag IDs, not tags.** Drupal collects the tags invalidated during a request. After the response, it stores them in an expirable key-value collection under a random, unguessable ID, for five minutes, and sends `POST {"id": "..."}` to each frontend that opted in. The frontend reads the tags with `POST` to a Drupal route, with the ID in the body, so access logs never record it. The route returns the tags once and forgets them. The announcement is only a hint: a forged one makes the frontend ask Drupal, which answers 404. The ID is the only credential, and the tags always come from Drupal, so no shared secret exists.

**Frontends opt in per URL.** Drupal contacts only the URLs in a dedicated setting, separate from the editor preview frontend list. Frontends that do not cache are never contacted.

**Everything expires when precision is lost.** Drupal announces `http_response`, a tag every Drupal response carries, when a request invalidates more than 1,000 tags, on a router rebuild and on a cache rebuild. A frontend that misses an announcement, because it was unreachable or answered with an error, is remembered and told `http_response` on its next announcement or at cron. A frontend that reads the tags after answering, and fails to read them, expires everything itself, because Drupal can no longer know.

**Tags that never reach published responses are not announced.** The auto-save tag is invalidated on every editor auto-save and only appears in preview responses, so announcing it would cost every frontend a round trip for nothing.

**Announcing never throws.** The invalidator is destructed last, to include invalidations other destructors make, and logs every failure instead of throwing, so later destructors still run. Redirects are not followed and requests time out, so a slow frontend cannot hold a PHP worker for long.

**The frontend side is framework-agnostic at the core.** One handler receives the announcement, reads the tags and calls an `invalidate(tags)` callback; adapters connect the callback to their cache. Frameworks with a tag cache use it (Next.js `revalidateTag()`, Astro's route cache `invalidate()`). Others pass the tags to the application, for example a CDN purge. The handler may answer before reading the tags where the framework allows running work after the response.

## Consequences

- No secret to configure, rotate or leak. A leaked ID is useful for at most five minutes, once, and reveals only tag names.
- Pages expire when what they depend on changes, including permissions and routing. Every content API response depends on `route_match` and `config:core.extension`, so saving a path alias or rebuilding the router expires every cached page.
- A replayed ID is harmless; a forged announcement costs the frontend one request to Drupal.
- A frontend that does not defer the read holds the announcing PHP worker for the duration of the read. `drush cache:rebuild` waits for the announcement, up to the timeout per unreachable URL.
- Announcements happen once per request, at the end, so a long drush command or migration announces when it finishes, usually `http_response`.
- A reverse proxy or CDN in front of the content API can serve a stale copy to a frontend refetching before the proxy purges. The content API has to be excluded from that cache, or purged first.
- A frontend that is not in the setting is never told. Production frontends have to be added explicitly.
- Tag length limits of the frontend's cache apply; Next.js drops tags longer than 256 characters.
