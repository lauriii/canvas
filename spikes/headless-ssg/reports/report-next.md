# Spike: SSG / static export with the Canvas Headless Next.js template

Empirical run against a live Drupal backend (`CANVAS_SITE_URL=http://127.0.0.1:32817`,
canvas_headless enabled). Spike dir: `<scratch>/spike-next` (left in place; build logs in
`<scratch>/build-*.log`). Content on the site was authored against different component
definitions than the template ships, so build-time renders emit `[canvas] Canvas component
"..." is not registered; omitted subtree ...` warnings — noise, ignored per brief.

## Stack

| Package | Version |
|---|---|
| next | 16.2.12 (Turbopack) |
| react / react-dom | 19.2.8 |
| @drupal-canvas/headless | 0.5.0 |
| @drupal-canvas/headless-next | 0.3.0 |
| @drupal-canvas/headless-react | 0.3.1 |
| Node | v26.5.0 |

## Attempt 1 — baseline `npm run build` (untouched template)

Build succeeds. Route summary:

```
Route (app)
┌ ○ /_not-found
├ ƒ /[[...slug]]
├ ƒ /api/canvas/component-preview
├ ƒ /api/canvas/components
├ ƒ /api/disable-draft
├ ƒ /api/draft
└ ƒ /api/draft/renew
```

Only `/_not-found` is static. The catch-all is dynamic **because the template pins it**:
`app/[[...slug]]/page.tsx` contains `export const dynamic = 'force-dynamic';`. Nothing else
needs to be inferred — the template ships request-time rendering by explicit decision, not
as a side effect of the adapter (see Attempt 8, which disproves the "adapter forces
dynamic" assumption).

## Attempt 2–4 — `output: 'export'` on the untouched app (config: `withCanvas({ output: 'export' })`)

Each build failed on the next incompatible piece; removed minimally and iterated. The
removal list:

1. **`app/api/draft/route.ts` (+ `/renew`)** — first failure:
   ```
   Error: export const dynamic = "force-static"/export const revalidate not configured on route "/api/draft" with "output: export".
   ...
   Error: Failed to collect page data for /api/draft
   ```
   Removed `app/api/draft/`.
2. **`app/api/canvas/components/route.ts`** (declares `export const dynamic = "force-dynamic"`):
   ```
   Error: export const dynamic = "force-dynamic" on page "/api/canvas/components" cannot be used with "output: export".
   ```
   Removed it, plus `app/api/disable-draft/` (same class of failure pending).
3. **`app/api/canvas/component-preview/page.tsx`** (the SDK's ComponentPreviewPage reads searchParams):
   ```
   Error: Route /api/canvas/component-preview with `dynamic = "error"` couldn't be rendered statically because it used `await searchParams`, `searchParams.then`, or similar.
   ```
   Removed all of `app/api/`.
4. **`withCanvas()`'s `headers` config** never fails the build but is announced dead twice:
   ```
   ⚠ Specified "headers" will not automatically work with "output: export". See more info here: https://nextjs.org/docs/messages/export-no-custom-routes
   ⚠ rewrites, redirects, and headers are not applied when exporting your application, detected (headers). See more info here: https://nextjs.org/docs/messages/export-no-custom-routes
   ```
   Left `withCanvas()` in place (its manifest/registry/transpile work still functions);
   the CSP `frame-ancestors` protection it injects silently does not ship in an export.
5. **The catch-all itself** then blocks:
   ```
   Error: Page with `dynamic = "force-dynamic"` couldn't be exported. `output: "export"` requires all pages be renderable statically because there is no runtime server to dynamically render routes in this output format.
   ```

## Attempt 5 — export + `generateStaticParams` + adapter `fetchPage`

Removed `force-dynamic`, added `generateStaticParams()` enumerating published paths from
`/jsonapi/canvas_page/canvas_page` and `/jsonapi/node/article` (status filtered
client-side — server-side `filter[status]=1` returns empty for canvas_page, known bug).
7 canvas pages (aliased) + 5 articles (`/node/{nid}`) + `/` = 13 paths. Kept the
**adapter's** `fetchPage` from `@drupal-canvas/headless-next`.

**`draftMode()`/`cookies()` did NOT throw during static generation.** The adapter's
`getDraftData()` calls `(await draftMode()).isEnabled` first; during prerender it resolves
to `false`, and the `cookies()` read behind it is never reached. The failure is instead the
core SDK's hardcoded fetch cache mode (`cache: 'no-store'` in
`packages/headless/src/server/content-api.ts`):

```
Error occurred prerendering page "/". Read more: https://nextjs.org/docs/messages/prerender-error
Error: Route /[[...slug]] with `dynamic = "error"` couldn't be rendered statically because it used `revalidate: 0 fetch http://127.0.0.1:32817/canvas/content-api?requestUri=%2F /[[...slug]]`.
```

## Attempt 6 — export + core framework-agnostic `fetchPage`

Swapped to `import { fetchPage } from '@drupal-canvas/headless/server'` with
`fetchPage(path, { baseUrl: process.env.CANVAS_SITE_URL })` (export surface confirmed in
`packages/headless/src/server/index.ts`; npm dist 0.5.0 exports it too). **Identical
failure** — the core client hardcodes the same `cache: 'no-store'`:

```
Error: Route /[[...slug]] with `dynamic = "error"` couldn't be rendered statically because it used `revalidate: 0 fetch http://127.0.0.1:32817/canvas/content-api?requestUri=%2Fabout /[[...slug]]`.
```

So the blocker for `output: 'export'` is not `next/headers` — it is the SDK's fetch policy,
in both the adapter and the framework-agnostic path.

## Attempt 7 — export + core `fetchPage` + `fetchImpl` cache override → SUCCESS

The core `fetchPage` accepts `fetchImpl`. Passing
`(input, init) => fetch(input, { ...init, cache: 'force-cache' })` got a full static
export:

```
Route (app)
┌ ○ /_not-found
└ ● /[[...slug]]
  ├ /
  ├ /about
  ├ /careers
  └ [+10 more paths]
```

Verification of `out/`:
- 13 per-page HTML files (`index.html`, `about.html`, ... `node/2.html` ... `node/11.html`) plus `404.html`/`_not-found.html`.
- Real content: `<title>Home</title>`, `<title>About</title>`, `<title>How to turn model
  output into product stories people remember</title>`; "Human expertise" present in
  `out/index.html`.
- Served via `python3 -m http.server 4331`: `/` → HTTP 200 (18,153 bytes), `/about.html` →
  200, `/node/2.html` → 200.
- **Backend URL leak: one, and it is media, not API plumbing.** `out/index.html` /
  `out/home.html` (and their RSC `.txt` payloads) embed an absolute image URL:
  `http://127.0.0.1:32817/sites/default/files/2026-05/photo-....jpg?alternateWidths=http%3A//127.0.0.1%3A32817/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/...`.
  Expected for Drupal-hosted media, but it means an SSG deploy needs the Drupal origin
  publicly reachable (or an asset pipeline) — the exported HTML hot-links backend files.

## Attempt 8 — hybrid: normal build (`output` unset) + `generateStaticParams`

Restored all of `app/api/`, reverted config to plain `withCanvas()`.

**8a — core `fetchPage` + `fetchImpl` override:** catch-all is ● SSG (13 paths), all five
API routes stay ƒ. Per-page HTML on disk in `.next/server/app/` (`about.html`,
`node/2.html`, ...), with real titles and "Human expertise" in `index.html`.

**8b — the unmodified adapter `fetchPage` (draftMode + no-store fetch), no overrides:**
**same result** — ● SSG with full per-page HTML:

```
Route (app)
┌ ○ /_not-found
├ ● /[[...slug]]
│ ├ /
│ ├ /about
│ ├ /careers
│ └ [+10 more paths]
├ ƒ /api/canvas/component-preview
├ ƒ /api/canvas/components
├ ƒ /api/disable-draft
├ ƒ /api/draft
└ ƒ /api/draft/renew
```

In Next 15/16 a `no-store` fetch no longer forces dynamic rendering in a normal build (it
is only fatal under `output: 'export'`, where the route runs as `dynamic = "error"`), and
`draftMode()` is prerender-safe. So **the hybrid shape works today with exactly two
template edits**: delete `export const dynamic = 'force-dynamic'` and add
`generateStaticParams`. Draft preview should survive intact: Next's `__prerender_bypass`
cookie re-renders SSG routes dynamically for draft sessions (not exercised end-to-end in
this spike). Caveat: published pages are frozen at build time — no ISR unless a
`revalidate` is added.

## with-canvas.ts / route-handlers.ts vs `output: 'export'`

`withCanvas()` (packages/headless-next/src/with-canvas.ts) does four things; two die under
export. The manifest generation + env inlining and the component-registry alias are
build-phase-only and export-safe. The `headers` config — the entire CSP `frame-ancestors`
story, including the cookie-matched rule that admits the draft editor origin — is a Next
server feature and is silently dropped in an export (warning only), so an exported site has
no SDK-provided frame-ancestors protection and could not be embedded/protected as designed.
`createDraftRouteHandlers()` (route-handlers.ts) is inherently server-bound: all three
handlers mutate per-request state through the adapter (`cookies().set`,
`draftMode().enable/disable`, redirect responses to `?assertion=` URLs), which cannot exist
in a static file server — and the component metadata endpoint is explicitly
`force-dynamic` (auth-gated per request by proof-by-redemption). None of these can be
stubbed statically; in an export the entire editor integration (draft preview, component
discovery, component preview iframe) is structurally absent. There is no middleware in the
template.

## Conclusion (what Next.js SSG needs from Canvas/SDK)

1. The template's `force-dynamic` on the catch-all is the only hard-wired blocker for hybrid SSG — removing it plus adding `generateStaticParams` yields full per-page prerendered HTML today, with draft routes intact as server functions.
2. The SDK needs a first-class page-enumeration helper (list published canvas_page + node paths via JSON:API) to feed `generateStaticParams`; today you hand-roll it, and the canvas_page `filter[status]=1` JSON:API bug forces client-side filtering.
3. Core `fetchPage`'s hardcoded `cache: 'no-store'` should become an option (default preserved): it is what breaks `output: 'export'` in both the adapter and framework-agnostic paths; `fetchImpl` works as an escape hatch but is undiscoverable.
4. Full static export additionally requires shedding the whole editor integration (draft routes, component metadata endpoint, component preview page, CSP headers) — acceptable for a "publish-only" tier, so the template could offer an export variant without those files rather than pretending they can be stubbed.
5. Backend-absolute media URLs (`/sites/default/files/...` + `alternateWidths`) hot-link the Drupal origin in exported HTML — SSG docs must require a public backend or the SDK needs an asset-URL rewrite hook.
