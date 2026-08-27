# Canvas Headless and static site generation (SSG): research report

Date: 2026-08-27. Canvas `1.x` at `ecbee05d` (current with upstream on that
date). Backend: the live site-3 clone (`https://site-3.ddev.site`, reached from
Node as `http://127.0.0.1:32817` because the farm host cannot resolve
`*.ddev.site`), with `canvas_headless` enabled. Frontend templates from
`drupal-canvas/headless-templates` at `a6d4288`. Spike apps live in the session
scratchpad (`spike-astro`, `spike-next`, `spike-nuxt`); their diffs, the
per-framework reports, and a committed copy of this document are preserved on
the scratch branch `headless-ssg-research` under `spikes/headless-ssg/` (this
repo-root copy stays untracked via `.git/info/exclude`, per the task brief).

**TL;DR.** Static builds against Canvas Headless work today, mostly by
accident: the content endpoint is anonymous for published content, and
JSON:API enumerates Canvas pages. All three attempted adapters produced
working static output against the live backend — Astro fully static and
Node-free (rendering matched its SSR output on the tested route), Next.js
both as a hybrid (static pages plus serverless draft routes, two template
edits) and as a full export (one SDK fetch-cache override), and Nuxt, where
the untouched template's `nuxi generate` is aborted by the adapter's own
preview route, but a prerender ignore plus an explicit route list (the
enumeration gap in miniature) makes it work. What is missing is not a
rendering mechanism but a contract: a supported route inventory (prototyped
in this spike; no client can reliably reconstruct which routes Canvas
renders, and JSON:API filters on `canvas_page` are silently broken besides),
a static/prerender profile in the SDK and adapters (today the integrations
assume a server), per-page cacheability exposure for incremental rebuilds,
and clear preview guidance. Editor preview inherently stays request-time:
Next should serve both from one deployment via its draft-mode bypass (the
build shape is verified; an end-to-end editor session is not), while Astro
and Nuxt need a second, server-rendered deployment for the editor. Static
artifacts that render media hot-link the Drupal origin, by design — the
whole story assumes published content and files are publicly readable.

---

## 1. Empirical results: what happens today

### 1.1 Astro (run first-hand; the SSG-native adapter)

Template: `headless-templates/astro` (astro 7.1.6, @astrojs/node 11.0.3,
@drupal-canvas/headless 0.5.0, @drupal-canvas/headless-astro 0.4.0).

Baseline (`output: 'server'`, untouched template): builds clean.

**Attempt 1 — naive static.** `output: 'static'`, adapter removed, `canvas()`
integration kept:

```
[NoAdapterInstalled] Cannot use server-rendered pages without an adapter.
```

Cause: the integration unconditionally injects five `prerender = false` routes
(`/api/draft`, `/api/draft/renew`, `/api/disable-draft`, the component metadata
endpoint, and `ComponentPreviewPage.astro`). The component preview page is
injected even with `injectRoutes: false`, so no configuration of the shipped
integration yields a fully static project.

**Attempt 2 — static output, node adapter kept (hybrid).** Fails next on:

```
[GetStaticPathsRequired] `getStaticPaths()` function is required for dynamic routes.
```

The template's catch-all `[...slug].astro` has no build-time page list. This is
the enumeration gap (section 2.2).

**Attempt 3 — hybrid plus `getStaticPaths` from JSON:API.** Added
`getStaticPaths()` that enumerates published `canvas_page` and `node--article`
resources anonymously via JSON:API (client-side `status` filtering; see the
filter bug in 2.2) and returns their `path.alias`. Build succeeds and
emits all 13 published paths as prerendered per-page HTML. Two warnings, both
informative:

- `Astro.request.headers was used when rendering ... not available on
  prerendered pages` on every page: the SDK's draft-session lookup reads
  cookies during prerender. Harmless (no cookies at build means anonymous
  fetch, which is correct), but noisy and un-obvious; a static profile should
  skip draft resolution entirely when prerendering.
- `[canvas] Canvas component "paragraph" ... is not registered; omitted
  subtree`: content references components the app does not implement. Not
  SSG-specific (SSR omits the same subtrees; verified) but in a build log it
  is the only place a content/app drift becomes visible, which argues for a
  build-time strict mode (section 4).

**Attempt 4 — fully static, no server at all.** Dropped the `canvas()`
integration, kept only the pieces a static build needs: the
`canvasComponentRegistry()` Vite plugin (the component implementation registry
`CanvasComponentTree.astro` renders from) plus `ssr.noExternal` for the SDK
packages, with `CANVAS_SITE_URL` passed as a real environment variable (the
integration's `.env` bridge is gone with the integration). Build succeeds:
13 HTML pages, 148 KB total, no server output. Served the `dist/` directory
with `python3 -m http.server` (a stand-in for any static host with no Node
runtime): pages render, including a page whose hero renders real site content
("Human expertise that amplifies what your AI agents can do", rich-text
description, button) after a prop-shape shim (below). No reference to the
Drupal origin appears in this artifact's HTML — but that is partly an
artifact of the test site's content mismatch: the image-bearing components
did not render (unregistered), and a page that does render media embeds
absolute Drupal-origin URLs (verified in the raw payload and in the Next and
Nuxt artifacts; see 2.3). The only runtime scripts are the draft-banner
component's client script (inert without a draft cookie) and, on pages using
the accordion, that component's vanilla script.

**Control: static output matches SSR output on the tested route.** The same
app built with `output: 'server'` and served by the node adapter renders `/`
with identical text content and markup structure to the prerendered file
(5,151 vs 5,141 bytes; the small delta is envelope-level, not content). One
route, on a site where several components did not render, is a narrow
control — but it exercised the full anonymous rendering path, and no hidden
request-time dependency surfaced in it.

**Preview coexistence probe.** In the hybrid build (attempt 3), the node
server serves prerendered pages statically and the draft routes stay alive
(`/api/draft` answers 422 "Missing preview assertion", the component metadata
endpoint answers 401 without auth). But Astro decides prerender per route at
build time: once `/about` is prerendered, `/api/draft`'s redirect back to
`/about` lands on the static file. The inference — stated as inference, since
no draft session was activated end-to-end in this spike — is that an editor
would silently see published content under an apparently active draft
session, because Astro has no per-request bypass for prerendered routes
(Next.js's `__prerender_bypass` cookie is exactly that mechanism) and the
prerendered page was baked with no draft state. An Astro SSG story therefore
needs the draft flow to re-enter through a dedicated server-rendered route
(for example a `prerender = false` `/_draft/[...slug]` mounted by the
integration), or a separate SSR deployment for preview. Fail-loud beats
fail-stale — though note the fully static shape's failure mode is
host-dependent: a plain file server 404s on `/api/draft`, while a host with
an SPA fallback (Nuxt's `200.html`, for instance) answers 200 with the app
shell, which is visible but not an explicit error.

Spike file inventory (Astro): `astro.config.mjs` variants (server / hybrid /
pure-static), `src/lib/static-paths.ts` (~50 lines, JSON:API enumeration with
pagination), a `getStaticPaths()` block in `[...slug].astro`, and a prop-shape
shim in the hero component so site-3's content (authored against a different
component library) renders visibly. Nothing else changed.

### 1.2 Next.js (sub-agent spike, outputs verified)

Template: `headless-templates/nextjs` (next 16.2.12, @drupal-canvas/headless
0.5.0, @drupal-canvas/headless-next 0.3.0).

**Baseline.** Builds clean. Route summary shows only `/_not-found` static;
the catch-all and all five API routes are dynamic (`ƒ`). Decisive detail: the
catch-all is dynamic because the *template* pins it with
`export const dynamic = 'force-dynamic'` — a deliberate template choice, not
an adapter constraint (disproved below).

**`output: 'export'`** fails on one incompatible piece after another, each
with a clear error; the removal list is the finding:

1. `app/api/draft` + `/renew`: `export const dynamic = "force-static" ...
   not configured on route "/api/draft" with "output: export"`.
2. `app/api/canvas/components` (declares `force-dynamic`):
   `cannot be used with "output: export"`.
3. The SDK's component preview page: `couldn't be rendered statically
   because it used await searchParams`.
4. `withCanvas()`'s `headers` config (the whole CSP `frame-ancestors` story)
   does not fail the build but is announced dead: `"headers" will not
   automatically work with "output: export"`. An exported site silently
   ships without the SDK's frame-ancestors protection.
5. Finally the catch-all's own `force-dynamic` blocks the export.

**The real SSG blocker is the SDK's fetch policy, not `next/headers`.** With
`generateStaticParams` added (JSON:API enumeration, same recipe as Astro) and
`force-dynamic` removed, prerendering fails identically through both the
adapter's `fetchPage` and the framework-agnostic core `fetchPage`:

```
Route /[[...slug]] ... couldn't be rendered statically because it used
`revalidate: 0 fetch http://.../canvas/content-api?requestUri=%2F`
```

That is `content-api.ts`'s hardcoded `cache: 'no-store'`. Notably,
`draftMode()`/`cookies()` did *not* throw during static generation — the
adapter checks `draftMode().isEnabled` first, which is prerender-safe and
false at build. Passing a `fetchImpl` that overrides the cache mode (the
core's undocumented escape hatch) produced a **successful full static
export**: 13 per-page HTML files with real content, served and spot-checked
over `python3 -m http.server`.

**Hybrid is two template edits away, today.** A normal build (no `output`
setting) with `generateStaticParams` and `force-dynamic` removed — and the
*unmodified* adapter `fetchPage`, no cache override — emits the catch-all as
`●` SSG (13 paths, real per-page HTML in `.next/server/app`) while all five
API routes stay serverless functions. In Next 15/16 a `no-store` fetch no
longer forces dynamic rendering in a normal build; it is only fatal under
`output: 'export'`. Draft preview should survive via the `__prerender_bypass`
cookie (Next's per-request static bypass, which the adapter already rides) —
not exercised end-to-end in this spike.

One leak in the exported HTML: absolute backend-origin media URLs (section
2.3).

### 1.3 Nuxt (sub-agent spike, outputs verified)

Template: `headless-templates/nuxt` (nuxt 4.5.1, nitropack 2.13.4,
@drupal-canvas/headless-nuxt 0.4.0).

**Baseline.** `npm run build` clean.

**`nuxi generate` on the untouched template FAILS**, and on the adapter's own
route: the module registers a real Vue page at
`/api/canvas/component-preview` which throws a 404 when the component-id
query is absent; the prerender crawler visits it bare, and any prerender
error aborts the generate:

```
[nitro] ├─ /api/canvas/component-preview (112ms)
  │ ├── [404] Server Error
 ERROR  Exiting due to prerender errors.
```

(`/` itself had already prerendered fine, with real backend content, before
the abort.)

**Two config keys make it work — one of which is the enumeration problem in
miniature.** `nitro.prerender.ignore` for the preview route plus
`nitro.prerender.routes` with a hand-maintained list of the published paths
(the crawler cannot discover pages that nothing links to, so the developer
owns exactly the list an inventory endpoint should provide): 28 routes
prerendered, all 13 pages emit `index.html` plus payload files, 556 KB total,
and — unlike Astro's hybrid — the generate output is **pure static by
construction**: no `.output/server` directory exists at all. Draft routes and
the metadata endpoint are simply absent (404 on a static host), and draft
state resolution silently no-ops during prerender (no cookies, anonymous
fetch, `enabled: false` baked into every page's payload).

Two Nuxt-specific caveats:

- Client-side navigation to any non-prerendered path calls the template's
  `/api/page` server route, which does not exist statically, so valid Drupal
  paths 404 on the client even though a full page load would... also 404.
  Static Nuxt is complete-enumeration-or-nothing.
- The tempting middle ground, `routeRules` with `swr`/`isr` (which keeps the
  Nitro server and therefore drafts), has a cache-poisoning hazard: page
  rendering is cookie-scoped (a live draft session carries a bearer token),
  and Nitro's route-rule cache does not vary on cookies by default. Naive
  `swr` could cache a draft render and serve it to anonymous visitors. Any
  ISR/SWR guidance must bypass the cache when the draft cookie is present.

### 1.4 TanStack Start (code assessment only; no build attempted)

The adapter (`packages/headless-tanstack-start`) is a Vite plugin plus handler
factories the app mounts as TanStack Start server routes. Everything is
designed around per-request server handlers on Nitro. TanStack Start does ship
a prerender feature (Nitro-based, like Nuxt's), so the mechanism is plausibly
the same as Nuxt's, but the framework's static story is the youngest of the
four and nothing in the adapter acknowledges it. Recommendation: explicitly
out of scope for a first SSG story; revisit when the framework's prerender
API stabilizes.

---

## 2. The six questions

### 2.1 Can published content be fetched at build time at all?

**Yes, anonymously; no OAuth needed.** `/canvas/content-api` is deliberately
public (`_access: 'TRUE'` in `canvas_headless.routing.yml`, `security: []` in
`modules/canvas_headless/openapi.yml`). The preview scope only switches the
endpoint into draft mode; without a token it renders the published revision,
exactly what an anonymous visitor may see. The SDK is already built for this:
`fetchPage()` in `packages/headless/src/server/content-api.ts` documents the
anonymous fallback ("without one ... the request is anonymous and resolves
only what anonymous visitors may see"), and a build has no draft cookies, so
every build-time fetch is anonymous by construction. On a site like the test
site — published content fully anonymous-readable — `client_credentials`
adds nothing.

**Precondition, stated explicitly:** the whole SSG story as researched
assumes published content and public files are anonymous-readable. A site
that gates published content (intranet permissions, node-access modules,
private files, a basic-auth'd or IP-allowlisted backend origin) gets an
empty or broken build with no error, because anonymous access resolution is
silent by design. Supporting such sites would require a build credential
(`client_credentials` or similar) that Canvas Headless does not offer today,
and media hot-linking (2.3) fails for them regardless. That is a scoping
decision the proposal records, not a solved problem. Two further caveats:

- The server helpers assume a request context only for *draft* resolution
  (cookies). The anonymous path needs none, and the request-scoped APIs
  turned out to be prerender-safe in practice (Next's `draftMode()` resolves
  false at build without throwing; Astro's cookie access only warns). The
  framework-agnostic `fetchPage(requestUri, { baseUrl })` in
  `@drupal-canvas/headless/server` works with no request in hand.
- `fetchPage()` hardcodes `cache: 'no-store'`, which is correct for draft
  preview but is the single hard blocker for Next's `output: 'export'`
  (verified through both the adapter and the core path; see 1.2). The
  `fetchImpl` option works as an escape hatch but is undiscoverable. Making
  the cache mode an option is a one-line SDK fix.

Server-side (correcting an earlier observation in this research that was an
artifact of probing with HEAD): anonymous GET responses from the content-api
*are* served by Drupal's internal page cache on repeat requests (verified:
`X-Drupal-Cache: HIT` on the second GET). External caching is a different
matter: with this site's default performance config the responses carry
`Cache-Control: must-revalidate, no-cache, private`, so no CDN or reverse
proxy caches them — that is site configuration (page cache max-age), not a
property of the endpoint. Build cost is therefore: a cold build renders each
page once regardless of caching; repeat builds without content changes hit
the internal page cache. A 10,000-page site rebuilding on every publish is
still a real load consideration and strengthens the case for incremental
rebuilds (2.5).

### 2.2 Page enumeration

**No supported inventory exists; JSON:API gets you most of the way with
sharp edges.** The Canvas Headless OpenAPI spec has no enumeration endpoint;
the content endpoint only resolves one URI per call. What works today, and
what the spike used: anonymous JSON:API collections
(`/jsonapi/canvas_page/canvas_page`, `/jsonapi/node/article`, ...) with
`path.alias` (falling back to the canonical `/page/{id}` and `/node/{nid}`
paths), following `links.next` pagination. The `headless-templates` even ship
reference code for exactly this (`src/lib/content.ts`, marked "nothing in the
template uses this module today").

The edges:

- **A client must know the entity-type list.** JSON:API is per-resource-type;
  the app has to enumerate canvas pages, then every node bundle rendered by a
  Canvas content template, then anything else routable. There is no "all
  Canvas-rendered routes" view. This is the single biggest missing piece, and
  it is not SSG-shaped: any crawler, sitemap generator, cache warmer, or
  search indexer needs the same inventory.
- **JSON:API collection filters are silently broken for `canvas_page`.**
  Verified on site-3: `?filter[status]=1`, `filter[title]=About`,
  `filter[langcode]=en`, and `filter[drupal_internal__id]=1` each return an
  empty collection with no error, while the unfiltered collection returns all
  7 pages and the same conditions match via entity query (both with and
  without access checks). Pagination (`page[limit]`) works; every tested
  filter returns empty. Node filters work fine on the same site. Not chased
  to root cause; deserves its own issue regardless of the SSG work, and it
  currently forces clients to filter published-ness client-side.
- **Unpublished pages are invisible anonymously** (correct for SSG), and
  `changed` timestamps are exposed, which is enough for incremental
  enumeration by polling, but only once filters work.
- **Blind JSON:API enumeration over-lists.** The spike enumerated articles
  because they exist as nodes, but on this site nothing makes them
  Canvas-managed (no content template exists): the content-api returns
  `managedByCanvas: false` and `content: null` for them, and the static
  builds emitted head-only shells at those paths. Only Canvas knows which
  routes it actually renders; a client reconstructing that from JSON:API
  cannot.

**Feasibility prototype (built; verified for what it does, incomplete by
design).** A ~140-line `RouteInventoryController` in `canvas_headless` (on
the spike branch) demonstrates the mechanism: it lists published Canvas
pages plus published entities of any bundle targeted by an enabled full-view
content template, with canonical alias paths, uuid, langcode, and `changed`
timestamps, offset-paginated, with real cacheability (per-source list cache
tags, template dependencies, URL cacheability bubbled). Verified live on
site-3: `GET /canvas/api/v0/headless/inventory` returns the 7 published
canvas pages and correctly excludes the non-Canvas-managed articles.
`ddev phpcs` and `ddev phpstan` clean; OpenAPI operation added; the module's
spec-validation and route-completeness tests pass. What it deliberately does
NOT cover, all recorded as production tasks in the OpenSpec change: the
front page (`/` resolves to a canvas page on this site but is absent from
the list — both spike builds hand-appended it), non-entity routes (views
pages and friends), per-language variants, keyset pagination, per-user
access variation beyond the `user.permissions` cache context, and entity
types without a published key (currently listed unfiltered). It reflects
the requesting account's access like the content endpoint does, which the
proposal's design pins down explicitly.

**The inventory does not make a static site complete.** It lists what
Canvas renders; a real Drupal site also serves views pages, non-templated
content, and redirects, which a fully static deployment must either
enumerate elsewhere, deliberately 404, or handle at the host (and pathauto
alias changes leave old URLs dead on a static host, where the redirect
module cannot run). The spike's static builds only covered "the whole site"
because blind JSON:API over-listing shipped head-only shells at non-Canvas
paths. The inventory answers "what does Canvas render"; "what does the site
serve" remains the site owner's enumeration problem, and the proposal keeps
it out of Canvas scope deliberately.

### 2.3 Components and islands

For the headless SDK adapters, hydration is the framework's own machinery, and
it is orthogonal to Canvas. The Astro template's components are plain `.astro`
templates plus one vanilla `<script>` (accordion-item), which Astro bundles
into static assets that work on any host — that much the spike exercised. No
framework island exists in any template (the Astro template has no framework
integration installed), so **island hydration on a static host was not
actually exercised**; that it would work is an expectation from Astro's
architecture (islands are build-time bundles independent of the data source),
not a spike result. Props and slots are baked into the HTML at build; apart
from media (below), the rendered pages need nothing from Drupal at runtime. A
component that fetches data client-side (a search box, a personalized block)
would hit JSON:API from the browser, which works against any static host as
long as CORS on the Drupal side allows it; nothing editor-gated is involved.
One population deserves a call-out: Drupal-authored code components (created
in the Canvas code editor, not shipped in the app) have no implementation in
the app's registry, so in a static build they are silently dropped subtrees —
the strict-mode argument below applies to them with full force.

`@drupal-canvas/astro-hydration` turned out to be a red herring for this
question: it is a private package the Canvas *module* uses to hydrate code
components in Drupal-rendered (non-headless) pages, not part of the headless
SDK path.

**Media is the real runtime dependency.** The content-api serializes media
props as absolute URLs on the Drupal origin, `alternateWidths` template
included (verified in the raw payload and in both the Next export and the
Nuxt payload files:
`http://127.0.0.1:32817/sites/default/files/...jpg?alternateWidths=...`).
This is why the Astro artifact's "no origin references" observation in 1.1
does not generalize: that artifact's image components simply failed to
render. This is by design — `canvas_headless` decorates `file_url_generator`
precisely because a cross-origin frontend needs absolute file URLs — but it
means a static artifact that renders media hot-links the backend origin for
every image. Three consequences for SSG: builds must run against the
*public* Drupal URL (an internal build-network URL gets baked into the
artifact — the spike's own artifacts embed `127.0.0.1:32817` and are
therefore non-portable, which also means the serve-and-curl spot checks
could not have caught broken images), "deploy it anywhere static files go"
still assumes the Drupal origin stays publicly reachable for media, and
private files are simply incompatible. A self-contained artifact needs an
asset pipeline (download-and-rewrite at build), which is app/adapter work,
not Canvas work.

One real gap: unregistered components are dropped silently (a console warning,
an omitted subtree, and a successful build). In a request-time app that is
arguably resilient; in a build that ships a frozen artifact it means content
loss that nobody sees until a visitor does. A static build wants a
`failOnUnregisteredComponent` (or at least fail-on-warning) switch.

### 2.4 Preview must stay dynamic

Confirmed at the mechanism level (1.1): draft preview is request-time and
authenticated by construction, so no static artifact can serve it. The
coexistence story differs per framework, and the differences are the finding:

- **Next.js** is the only adapter where one deployment can plausibly serve
  both: pages prerender at build, and `draftMode()` (the `__prerender_bypass`
  cookie, which the adapter already sets) switches an editor's request to
  request-time rendering on the same route. This is Next's intended design
  and the SDK already rides it — but the spike verified the *build shape*
  only (static pages coexisting with live draft routes); an end-to-end
  editor draft session against a hybrid deployment was not exercised and is
  a task in the proposal.
- **Astro** decides prerender per route at build time with no per-request
  bypass. Hybrid output keeps the draft API routes alive, but a draft
  session's redirect lands on prerendered files — by inference (see 1.1),
  silently showing published content. Two modes in one app means either a
  server-rendered draft route namespace (integration-mounted
  `prerender = false` catch-all that the draft activation redirects into),
  or two builds of the same codebase (static for production,
  `output: 'server'` for a preview deployment) with the editor's frontend
  URL pointing at the preview deployment. The second is honest and cheap;
  the first is nicer and is adapter work, not Canvas-module work.
- **Nuxt**: `nuxi generate` discards the server outright, so static Nuxt is
  necessarily the two-deployment shape; draft resolution no-ops silently
  during prerender and the banner silently never appears on the static
  deploy. The SWR/ISR middle ground keeps drafts but carries the
  cookie-cache-poisoning hazard (1.3).

What a developer must keep in sync in the two-deployment shape: one codebase,
two build commands, and the Canvas editor's configured frontend URL pointing
at the SSR deployment while the public DNS points at the static one. The
draft-session banner, CSP `frame-ancestors` middleware, and component
metadata endpoint only exist on the SSR deployment, which is also the only
origin that needs to be embeddable in the editor iframe. Component sync also
runs against the SSR deployment, which keeps Drupal's component registry
matching whatever that deployment ships — it is the developer's job that the
static build is the same code, or placed components and implementations
drift. One more asymmetry worth documenting: the SSR deployment always shows
current published content, while the public static artifact is stale until
the next rebuild — an editor "verifying" a publish on the preview origin is
not seeing what visitors see.

### 2.5 Invalidation

SSG discards Drupal's cacheability metadata, so staleness moves to rebuild
triggers. Findings:

- **Canvas emits no publish webhook.** The only Canvas event is `PushEvent`
  (CLI push lifecycle). Publishing an auto-save saves entities through
  standard Drupal APIs, so standard cache tags are invalidated and standard
  entity hooks fire; anything webhook-shaped is left to contrib (build_hooks
  et al.) or custom code today.
- **Cache tags are computed and then thrown away at the edge.** The
  content-api response is a `CacheableJsonResponse` whose tags identify
  exactly which entities/config a page depends on, but they are not exposed
  in the payload and appear as headers only with core's debug setting. A
  build that recorded per-page tags could map "tag X invalidated" to "rebuild
  pages 3, 17, 41". That is the natural, generic invalidation currency in
  Drupal, and it is already computed per response.
- **Front End Hosting webhooks**: could not be verified. The developer-portal
  repo was inaccessible with the available credential (contents read
  returned 403; noted in "What was not verified"). Public Acquia docs
  document Code Studio CI as the rebuild path for Basic and say nothing
  about payload contents. The honest framing: whatever the platform's
  trigger carries, Canvas today provides no endpoint a rebuild can ask
  "what changed since T?", so even a rich webhook could only trigger full
  rebuilds. `changed` timestamps via JSON:API plus per-page tag recording
  would enable incremental rebuilds without any new push infrastructure.
- **ISR as middle ground**: on Next.js (FEH Advanced has the shared
  filesystem for it), ISR gives per-page regeneration with no enumeration or
  webhook needs beyond a revalidation window, at the cost of a Node runtime.
  It is the pragmatic answer for large sites until incremental static
  rebuilds exist.

### 2.6 Per-adapter reality

| Adapter | Static story today | Verdict |
| --- | --- | --- |
| Astro | Fully static proven end-to-end; shipped integration must be bypassed (its routes force a server); needs `getStaticPaths` | **Viable now.** Adapter needs a static profile; hybrid additionally needs a draft re-entry route because Astro has no per-request prerender bypass |
| Next.js | Hybrid (static pages + serverless draft routes) builds today with two template edits and the unmodified adapter; full `output: 'export'` works with a fetch-cache override and shedding the editor integration | **Most complete story on paper.** The only adapter where one deployment can serve static pages *and* editor preview (draft-mode bypass — build shape verified, editor session not); ISR available on the same shape |
| Nuxt | `nuxi generate` fails untouched (adapter's preview page 404s the crawler); two config lines fix it; output is pure static, server discarded | **Viable now** with guardrails; adapter must make its preview route prerender-safe; SWR/ISR needs a draft-cookie cache bypass before it can be recommended |
| TanStack Start | Server-handler-centric adapter; framework prerender young; not attempted | **Do not attempt yet** |

---

## 3. Why this matters: the hosting-tier business case, verified and bounded

Acquia Front End Hosting has two tiers. Verified against public Acquia docs
(the developer portal's own hosting page was inaccessible; see section 5):
**Basic** hosts "fully static sites" with an explicit "Node.js runtime is
not available", no SSR, CDN-fronted, with a 10 GB artifact cap (cap from the
public features page as surfaced in search; the page itself did not render
the number for me). **Advanced** provides the Node runtime, auto-scaling,
and a shared filesystem supporting Next.js ISR. Canvas Headless as shipped
requires Advanced: every adapter's default is a server build, and the editor
integration is server-bound. The spike shows the render side of Basic works.
On artifact size, the honest statement is narrower than the numbers suggest:
the 148 KB Astro artifact is not representative — it is small because (most of the site's
components did not render, and no image did; the Nuxt artifact of the same
13 pages was 556 KB with payload files). The stronger size argument is
structural: media is hot-linked from the Drupal origin rather than shipped
in the artifact, so pages are text plus CSS/JS and the 10 GB cap is remote
even for large sites — unless a future asset pipeline inlines media, which
would change that arithmetic.

The honest bounds on the business case:

- **Production page serving moves to Basic; the editor experience does
  not.** Astro and Nuxt static sites need a server-rendered deployment
  somewhere for draft preview and component sync — a dev-server, a small
  Advanced environment, or any Node host. Next.js can keep both in one
  deployment, but that deployment is then Advanced anyway. So "Basic" is
  accurate for the public origin, not for the whole system.
- **The Drupal origin stays load-bearing**: media is hot-linked from it, and
  full rebuilds re-render every page against it (a cold build gets no cache help).
- **The subset that fits**: sites whose public content is fully published,
  anonymous-visible, and enumerable — brochure sites, marketing sites,
  docs. Sites needing per-request personalization, access-controlled
  content, or forms handled by the frontend server stay on Advanced.
  Large sites (tens of thousands of pages) fit only once incremental
  rebuilds exist; until then Next ISR on Advanced is the practical answer.

## 4. What Canvas would need to add (ranked)

Everything below is deliberately generic (useful to any client that walks the
site), per the constraint that Canvas must not grow an SSG-shaped special
case.

1. **A route inventory (Canvas module).** The single biggest gap: which
   routes Canvas actually renders is knowledge no client can *cheaply*
   reconstruct (a client can probe `managedByCanvas` per candidate URI
   through the content-api, but that is one request per guess, and blind
   JSON:API enumeration over-listed non-Canvas-managed articles on the test
   site). A paginated, anonymous-readable, cacheable endpoint listing
   Canvas-rendered paths with `changed` timestamps and language variants.
   Generic consumers: SSG builds, sitemap generators, cache warmers,
   crawlers, search indexers. The mechanism is prototyped and running (see
   2.2); production work includes the front page, non-entity routes,
   translations, pagination stability, access-variation caching, and tests.
2. **Fix JSON:API collection filtering for `canvas_page` (Canvas module).**
   Silently-empty filtered collections are a correctness bug independent of
   SSG, and any incremental enumeration (`changed > T`, `status = 1`)
   depends on it.
3. **A static/prerender profile in the SDK and adapters (npm packages).**
   The empirically observed pieces, smallest first: make `fetchPage`'s cache
   mode an option instead of hardcoded `no-store` (the Next export blocker);
   make the Nuxt preview route prerender-safe (it aborts every
   `nuxi generate` today); an Astro integration option that skips route
   injection and the preview page for static builds; an enumeration helper
   (consuming 1) that feeds `getStaticPaths` / `generateStaticParams` /
   `nitro.prerender.routes`; a fail-on-unregistered-component build switch
   in the shared renderer; template guidance (Next's `force-dynamic` pin is
   a template decision that forecloses the hybrid shape that already works).
4. **Expose per-page cacheability to API consumers (Canvas module).**
   Include the response's cache tags in the content-api payload (or an
   opt-in header without core's global debug setting), so a build can record
   dependencies per page. This is the enabler for incremental rebuilds and
   costs nothing at request time (the tags are already computed).
5. **An invalidation feed, later.** Only after 1+4 exist is a push signal
   worth designing (a queue/webhook of invalidated cache tags since T, or a
   polling endpoint). Out of scope for the first cut: ISR on Next covers the
   interim for large sites, full rebuilds cover small ones, and Canvas emits
   no publish event today that could carry it anyway.

## 5. What was not verified

- The developer portal's hosting-tier page (`source-cms/deploy/choose-hosting`)
  and its webhook documentation: the scoped credential could not read the
  upstream repo's contents (403). The Basic/Advanced framing was instead
  verified against public Acquia docs (Basic: fully static, explicitly no
  Node runtime, no SSR, CDN-fronted; Advanced: Node runtime, auto-scaling,
  shared filesystem for Next ISR). The 10 GB Basic artifact cap appears in
  public search results for the features page; the spike's whole artifact is
  148 KB, so the cap is not a practical constraint for Canvas-shaped sites.
- The full editor-preview loop against a hybrid/static deployment (would need
  the frontend registered in site-3's editor and the iframe flow exercised;
  the mechanism-level probes in 1.1 stand in for it). In particular, the
  claim that Next's `__prerender_bypass` draft mode serves request-time
  drafts on prerendered routes in the hybrid shape rests on Next's
  documented design and the adapter's existing use of it, not on an
  end-to-end editor session in this spike.
- ISR/SWR was assessed, not deployed (no FEH environment in the loop).
- The Astro hybrid "draft session silently sees published content" claim is
  a mechanism-level inference (route liveness plus Astro's prerender model),
  not an observed editor session; it is marked as such where asserted.
- Island hydration on a static host: no template ships a framework island,
  so none was built or hydrated; only vanilla component scripts were
  exercised.
- Multilingual enumeration (site-3 is monolingual; JSON:API exposes langcode
  but the spike did not exercise translated paths).
- Behavior of a Next hybrid deployment for paths published after the build
  (on-demand rendering of unknown params) was not exercised.
- Root-cause of the `canvas_page` filter bug (reported as observed behavior).
