# 22. Headless content invalidation primitives

Date: 2026-09-04

Issue: <https://git.drupalcode.org/project/canvas/-/work_items/3592101>

## Status

Accepted

## Context

A decoupled frontend that caches or pre-renders Canvas content, whether as a
full static build or through incremental static regeneration (ISR), goes stale
when content is published. Keeping it fresh without blindly re-fetching
everything needs two signals Canvas did not expose:

- What each rendered page depends on. The content endpoint computes full
  cacheability metadata (the cache tags of every entity and config object a
  page rendered from), but discards it at the edge, so a consumer cannot
  record per-page dependencies.
- When something is published. Canvas emitted no publish event; the only
  event was the CLI push lifecycle. A consumer had to poll to notice changes.

These are independent of how a site is built: they serve a full static rebuild
(map an invalidated tag to the pages to rebuild) and on-demand ISR
revalidation (revalidate exactly the paths an edit touched) equally. They are
deliberately separated from the static-build machinery (route enumeration, the
SDK static profile) described in ADR 0021.

## Decision

Add two invalidation primitives to Canvas Headless:

1. **Per-page cacheability exposure**: every content response carries a
   `cacheability` object with the cache tags the rendered page depended on.
   The tags are already computed for the response and are small, so they are
   always included rather than gated behind a flag; the SDK surfaces them on
   the page result. The tradeoff is that internal cache-tag names become
   visible on the anonymous content endpoint, which is acceptable for public
   content whose component and config identities are not sensitive.

2. **Publish notification**: a `PublishedEvent` dispatched from the auto-save
   publish pipeline after the transaction commits, carrying references to the
   published entities (entity type, id, uuid, langcode) and the cache tags the
   publish invalidated (the union of each entity's cache-tags-to-invalidate),
   and a configurable webhook in Canvas Headless that posts them to an external
   URL on publish. Auto-saving never dispatches; only publishing does. Delivery
   is best effort: a failure is logged and never fails the publish. The
   optional signing secret is never stored in config, so a config export never
   carries it into version control; it lives in State (settable with
   `drush state:set` or a deploy step, so it works where settings.php is not
   editable), with a settings.php override for locked-down environments.

3. **Revalidation tooling** in the SDK, across every adapter. The core
   exposes the shared spine (`readPublishWebhook()`, plus
   `verifyPublishSignature()` / `parsePublishPayload()`) so any framework's
   route handler verifies the HMAC and reads the payload once, and
   `surrogateKeyHeader()` for emitting a `Surrogate-Key` response header. Each
   adapter ships a revalidation handler on top: Next.js maps the tags to
   `revalidateTag()` (turnkey); Nuxt invalidates the Nitro cache; TanStack
   Start and Astro verify and delegate to an app-supplied `revalidate`
   callback, because those frameworks have no native tag-revalidation API
   (Astro's is host-specific). Combined with per-page cacheability (item 1), a
   consumer that tags each cached fetch with the page's tags gets tag-based
   on-demand revalidation of exactly the affected pages, indirect dependencies
   included; a CDN-fronted deployment can instead purge by the surrogate keys.

## Consequences

- A consumer can record path -> tags at build or fetch time and, on the
  publish webhook, rebuild or revalidate only the affected pages. No new
  invalidation infrastructure is required for that loop beyond these two
  primitives.
- The richer feed (a replayable log of invalidated cache tags, which capture
  indirect dependencies that entity references alone cannot) is deferred; it
  builds directly on the publish event and the cacheability exposure shipped
  here.
- Entity references, not cache tags, are the first-cut webhook payload: they
  are cheap to produce and enough for the rebuild and ISR loops. Cache tags
  remain the richer currency for the deferred feed.
- The webhook is best effort with no retry queue; a consumer that needs
  delivery guarantees should reconcile against the route inventory or content
  timestamps rather than rely on the webhook alone.
