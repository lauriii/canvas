# 21. Headless delivery contract for static builds

Date: 2026-09-04

Issue: <https://git.drupalcode.org/project/canvas/-/work_items/3592100>

## Status

Accepted

## Context

Canvas Headless is request-time by design: the editor embeds a decoupled app
that renders drafts through user-bound tokens. But much of a site's production
traffic is published content only, and static hosting tiers (no Node runtime,
CDN-fronted, cheaper, smaller attack surface) cannot run the request-time
stack. An empirical spike showed that all three attempted framework adapters
(Astro, Next.js, Nuxt) can already produce static builds against a live
backend, because the content endpoint (`/canvas/content-api`) is deliberately
anonymous for published content. What blocked a supported static story was not
rendering but the absence of a contract around it:

- No way to ask Canvas which routes it renders. A build had to reconstruct the
  list from JSON:API per entity type, which over-lists content Canvas does not
  manage and requires knowledge only Canvas has. Worse, JSON:API collection
  filters on `canvas_page` silently returned empty result sets.
- The SDK and adapters assumed a server, and the content client hardcoded a
  no-store fetch that breaks a static export build.

(Keeping a built artifact fresh after a publish is a related but separate
concern, addressed in ADR 0022.)

## Decision

Add a small delivery contract to Canvas Headless, all of it generic enough to
serve any client that walks the site, not just a static build:

1. **Route inventory endpoint** (`/canvas/api/v0/headless/inventory`): a
   public, published-only, keyset-paginated list of the paths Canvas renders
   (Canvas pages plus content entities whose bundle has an enabled full-view
   content template), one entry per published translation, with the front page
   emitted as an extra `/` entry, each carrying the canonical path, entity
   identity, language, and last-changed timestamp. Access mirrors the content
   endpoint: results reflect the requesting account's entity access, so an
   anonymous request sees the anonymous view.

The SDK gains the matching build-time pieces: a fetch cache-mode option (the
static-export blocker), an enumeration helper that walks the inventory, an
opt-in strict renderer mode that fails a build on an unregistered component,
and per-adapter static profiles.

Keeping a static artifact fresh after a publish (per-page cacheability
exposure and a publish notification) is a separate concern, decided in
ADR 0022.

The JSON:API filter defect is fixed at its root: `canvas_page` lacked a
`hook_jsonapi_ENTITY_TYPE_filter_access` implementation, so JSON:API's query
guard secured every filtered collection query with an always-false condition.

## Consequences

- A published, anonymous-readable Canvas site can be built to a fully static,
  Node-free artifact and deployed to a static-only hosting tier. The
  gated-content case (published content behind access control, private files)
  is deliberately out of scope for anonymous builds; supporting it would need
  a build credential the delivery contract could later honor without changing
  its shape.
- Editor preview stays request-time and authenticated. Next.js can serve both
  static pages and preview from one deployment via its draft-mode bypass;
  Astro and Nuxt use a second, server-rendered deployment for the editor.
- Media is serialized as absolute URLs on the Drupal origin, so static
  artifacts hot-link the backend for files, and builds must run against the
  public site URL.
- The route inventory answers "what does Canvas render", not "what does the
  site serve": views pages, non-templated content, and redirects stay the site
  owner's concern.
