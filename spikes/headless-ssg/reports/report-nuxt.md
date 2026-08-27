# Spike: SSG (`nuxi generate`) on the Canvas Headless Nuxt template

Empirical run, 2026-08-27. Spike dir: `<scratch>/spike-nuxt` (copy of `<scratch>/headless-templates/nuxt`, `.agents` and `skills-lock.json` excluded). Backend: live Drupal with `canvas_headless`, `CANVAS_SITE_URL=http://127.0.0.1:32817` in `.env` (the SDK reads `process.env.CANVAS_SITE_URL` in `packages/headless/src/server/config.ts`; the template ships `.env.example` naming it). Full logs kept: `<scratch>/build-baseline.log`, `generate-untouched.log`, `generate-routes.log`.

## Stack versions

| Package | Version |
|---|---|
| nuxt | 4.5.1 |
| nitropack | 2.13.4 (Vite 8.1.5) |
| vue | 3.5.40 |
| @drupal-canvas/headless | 0.5.0 |
| @drupal-canvas/headless-nuxt | 0.4.0 |
| Node | v26.5.0 |

## Attempt 1 — baseline `npm run build` (untouched template)

Command: `npm run build`. **Completes cleanly** (exit 0). Component manifest written (`[canvas] Wrote the component manifest: 18 component(s), 0 warning(s).`), server bundle emitted, ends with `✨ Build complete!` and `[nitro] ✔ You can preview this build using node .output/server/index.mjs`.

## Attempt 2 — `npx nuxi generate` (untouched template): FAILS

Command: `npx nuxi generate`. Exit 1. The Vue/Nitro builds succeed; the prerender phase aborts:

```
[nitro] ℹ Prerendering 3 initial routes with crawler
[nitro]   ├─ /200.html (491ms)
[nitro]   ├─ /404.html (495ms)
[nitro]   ├─ / (1113ms)
[nitro]   ├─ /api/canvas/component-preview (112ms)
  │ ├── [404] Server Error
  │ └── Linked from /
Errors prerendering:
[nitro]   ├─ /api/canvas/component-preview (112ms)
  │ ├── [404] Server Error
 ERROR  Exiting due to prerender errors.
```

Root cause (verified in adapter source): `packages/headless-nuxt/src/module.ts` registers a real Vue **page** route at `CANVAS_COMPONENT_PREVIEW_PATH = '/api/canvas/component-preview'` via `extendPages()`. `packages/headless-nuxt/src/runtime/pages/component-preview.vue` does `if (!componentId) { throw createError({ statusCode: 404 }); }` when the preview query param is absent. The generate crawler discovers the path (the route string ships in the client router chunks; Nitro reports it as "Linked from /"), visits it with no query, gets the 404, and `nuxi generate` treats any prerender error as fatal.

Notably, `/` itself prerendered fine before the abort: `.output/public/index.html` was emitted with real backend content ("Human expertise that amplifies what your AI agents can do…"). The crawler found no other page links (sparse content — the site's component IDs `paragraph`, `grid`, `image-feature`, `pricing-table` are not in the template registry; each logs a non-fatal `ERROR [canvas] Canvas component "…" is not registered; omitted subtree at "tree:default:…"`). So the untouched-template route list was just `/`, `/200.html`, `/404.html`, plus the fatal preview route.

## Attempt 3 — explicit route list + ignore: SUCCEEDS

Change (the only one): in `nuxt.config.ts` under the existing `nitro` key —

```ts
prerender: {
  routes: ['/about','/careers','/register','/login','/home','/services','/blog',
           '/node/2','/node/3','/node/5','/node/8','/node/11'],
  ignore: ['/api/canvas/component-preview'],
},
```

Command: `npx nuxi generate` (after `rm -rf .output`). Exit 0:

```
[nitro] ℹ Prerendering 15 initial routes with crawler
...
[nitro] ℹ Prerendered 28 routes in 21.834 seconds
[nitro] ✔ Generated public .output/public
└  ✨ You can now deploy .output/public to any static hosting!
```

The same "is not registered; omitted subtree" errors were logged again — they are noisy (`ERROR` level) but non-fatal.

Output verification:
- All 13 page routes emit `.output/public/**/index.html` (`/`, the 7 aliases, the 5 nodes) plus `200.html`/`404.html` and a per-route `_payload.json`. Total `public/` size 556 KB.
- Real content: "Human expertise" present in `/` and `/home` HTML; `/about` has `<title>About</title>`; `/node/3` has `<title>From campaign concepts to measurable pipeline impact</title>`.
- **CANVAS_SITE_URL leakage**: not in any emitted HTML. It **does** appear in `_payload.json` for `/` and `/home` — as an absolute Drupal **media file URL** in image component props: `"http://127.0.0.1:32817/sites/default/files/2026-05/…jpg?alternateWidths=…"`. So a static deploy hard-references the backend origin for media (broken images if the build-time backend URL is not publicly reachable). The API base itself is never serialized.

## Step 4 — is `.output/public` a pure static site?

Yes. With `generate` the output is only `nitro.json` (`"preset": "static"`) and `public/` — **no `.output/server` directory exists at all**. The adapter's Nitro handlers (`/api/draft`, `/api/draft/renew`, `/api/disable-draft`, `/api/draft/session`, `/api/canvas/components`) and the template's own `/api/page` + `/api/content` are simply absent from the artifact; they did not break the generate (they were never crawled), they just don't exist on a static host.

Served with `python3 -m http.server 4332` from `.output/public`:

- `GET /about/` → 200 (6,610 bytes; `/about` without slash → 301, python's directory redirect)
- `GET /node/3/` → 200, correct `<title>`
- `GET /api/draft` → 404, `GET /api/draft/session` → 404, `GET /api/canvas/components` → 404, `GET /api/page?path=/about` → 404

Consequence: prerendered-to-prerendered client navigation works off `_payload.json`, but any client-side navigation to a path that was not prerendered hits `useFetch('/api/page')` → 404 → the template's "Not found" state, even if the path exists in Drupal. The editor-facing surface (draft mode, component metadata endpoint, component preview page) is entirely gone from a static deploy.

## Step 5 — draft state during prerender

Draft state is per-request cookies read through h3: `createNuxtDraftAdapter(event)` (`runtime/server/adapter.ts`) wraps `getCookie(event, 'canvas_headless_draft_mode')` etc., and `getDraftServer(event)` builds one SDK draft server per request. During prerender, Nitro invokes handlers in-process with cookie-less requests, so the whole path **silently no-ops**: no cookie → draft flag off → `getClient()` anonymous → published content fetched. No draft-related error appeared in any log. The `<DraftSession>` component `await useFetch('/api/draft/session')`s on every page; during prerender that resolved in-process and the result is baked into every page's payload — verified in `/about/_payload.json`: `{'enabled': False, 'tokenExpiresAt': None, 'expired': False, 'renewUrl': None, 'editorOrigin': None}`. On a static host the same fetch on client navigation 404s, `session` stays null, the banner never renders — a silent degradation, not an error. Draft mode as designed (httpOnly cookies + per-request bearer token) is structurally impossible on a purely static deploy; it needs the Nitro server that `generate` discards.

## Step 6 — ISR/SWR route-rules assessment (not deployed; assessment only)

`routeRules: { '/**': { prerender: true } }` is just another spelling of attempt 3 and would hit the same two issues (the preview page 404 and the need to enumerate unlinked paths). `swr`/`isr` rules keep the full Nitro server (or a platform preset), so the draft routes and `/api/page` survive — that shape is viable today with zero adapter changes, and is the natural "static-ish" target for this template. But one caution follows directly from what the spike showed: page HTML is produced from cookie-scoped state (`useFetch('/api/page')` carries the draft bearer token when a session is live), and Nitro's route-rule cache does not vary on cookies by default — so naive `swr` on page routes risks a draft-session render being cached and served to anonymous visitors. Any ISR/SWR guidance for this template must exclude `/api/draft*` and either bypass the cache when the `canvas_headless_draft_mode` cookie is present or scope caching to the anonymous client.

## Final outcome

**`nuxi generate` works today with two lines of configuration, and does not work untouched.** The untouched template aborts on the adapter's own `/api/canvas/component-preview` page (bare visit → thrown 404 → fatal prerender error). With `nitro.prerender.ignore: ['/api/canvas/component-preview']` plus an explicit `nitro.prerender.routes` list (needed in practice regardless: the crawler can only find what published pages link to), all enumerated published paths emit correct static HTML with real backend content, and `.output/public` serves standalone. What you give up is everything server-side: draft mode, the component metadata endpoint, the preview page, and runtime resolution of non-prerendered Drupal paths.

## Conclusion — what Nuxt needs from Canvas/SDK for clean SSG

1. The adapter must make `/api/canvas/component-preview` prerender-safe: auto-add it to `nitro.prerender.ignore` (or return 200 with an empty shell when bare) instead of letting a thrown 404 abort every `nuxi generate`.
2. The SDK needs a build-time page-enumeration helper (JSON:API-backed "list all published paths") the adapter can feed into `prerender.routes` — crawling is insufficient because Canvas pages don't reliably link to each other; today users must hardcode the list. (Server-side `filter[status]=1` on canvas_page silently returning empty needs fixing for this.)
3. Media URLs are serialized as absolute backend-origin URLs into payloads/props; SSG needs a rewrite-to-relative or asset-download story so static builds don't hard-depend on the build-time `CANVAS_SITE_URL` origin.
4. Draft mode should detect the static preset and degrade explicitly (skip the session fetch / omit DraftSession) rather than baking `enabled:false` payloads and 404ing on a route that doesn't exist; document that draft preview requires the server output.
5. For the middle path, ship vetted `routeRules` guidance: `swr`/`isr` keeps drafts working, but the cache must bypass or vary on the `canvas_headless_draft_mode` cookie or draft renders can be cached for anonymous visitors.
