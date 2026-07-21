# Canvas Headless example app (Nuxt)

A Nuxt app the `canvas_headless` module embeds in the Canvas editor
frame — the Nuxt counterpart of the sibling `next`, demonstrating
the module's draft-preview authentication end to end:

- Draft mode activates by exchanging a Drupal-signed, single-use preview
  assertion (RFC 7523 JWT-bearer grant) for an access token bound to the
  initiating editor. No client secret anywhere.
- The front page lists articles and Canvas pages via JSON:API (draft
  sessions see working copies), fetched through the app's own server
  route (`server/api/content.get.ts`) where the session cookies live.
- Every other path resolves through Drupal's routing via the SDK's
  `fetchPage()` (proxied by `server/api/page/[...path].get.ts`).
  `<CanvasComponentTree>` recursively renders the returned tree using every
  local component implementation discovered by the SDK. Adding or removing a
  component updates the registry without restarting `nuxt dev`.
- The session renews in place over an origin-checked postMessage protocol
  with the embedding Canvas editor. A Nitro-rendered page has no
  Next.js-style server refresh, so the SDK's `<canvas-draft-session>`
  element re-arms from the renew endpoint's `{tokenExpiresAt}` answer —
  no document reload.
- Renewal is PKCE-bound to the app server: an injected script can
  intercept a relayed assertion and still get no token.
- Exiting draft mode is a `POST`, submitted by a form in the banner: it
  clears the session cookies, and a `GET` link to it would be eligible for
  prefetching.
- `/api/canvas/components` exposes the app's component registry (every
  component under `app/components`) to the
  embedding Drupal Canvas site, protected by proof-by-redemption.

The Drupal Canvas Headless SDK lives in the workspace packages
`@drupal-canvas/headless` (framework-agnostic core) and
`@drupal-canvas/headless-nuxt` (the Nuxt adapter); this app consumes them
and keeps only the wiring: the module entry in `nuxt.config.ts` (which
mounts all the API routes — no draft route files in this app), the banner
markup (`app/components/DraftBanner.vue`), and rendering.

## Setup

The packages arrive as `file:` links into this repository: the SDK core
from its compiled `dist`, the adapter package as TypeScript source
compiled by the app's builds. Their own dependencies resolve through the
Canvas repository's workspace install, so from the Canvas repository root
**run `npm install`, then `npm run build -w packages/headless`**, then:

```bash
cp .env.example .env    # defaults match the canvas-env DDEV setup
npm install
npm run dev             # http://localhost:3000
```

`CANVAS_SITE_URL` uses DDEV's http URL because Node.js does not trust
DDEV's mkcert certificate by default. To use the https URL instead, run
with `NODE_EXTRA_CA_CERTS="$(mkcert -CAROOT)/rootCA.pem"`.

Add `http://localhost:3000` from Canvas's **Headless frontends** screen
(the Next.js example uses the same port; run one at a time, or move one).
Embedded draft mode relies on CHIPS partitioned cookies; on a plain-http
localhost origin that works in Chromium-based browsers (localhost is a
trustworthy origin).

The production build runs the Nitro Node server:

```bash
npm run build
node .output/server/index.mjs
```

To copy this app out of the repository, replace the `file:` specifiers in
package.json with published package versions (the packages are not yet
published).

## The component metadata endpoint

`GET /api/canvas/components` answers the component registry: every
component under `app/components/` (set in `canvas.config.json`) with a
`component.yml`, in a versioned JSON envelope. Drupal Canvas reads it to
register the app's components.

- **Auth**: the caller presents a Drupal-minted preview assertion as a
  Bearer token; the app verifies it by redeeming it at Drupal's
  `/oauth/token` (proof-by-redemption — only the embedding Drupal can mint
  one). Assertions are single-use: mint a fresh one per request.
- **Build time vs request time**: the module writes
  `.canvas/components.manifest.json` during `nuxt build`, and the endpoint
  serves that manifest in production — the registry describes the deployed
  build. In development it scans the codebase live on every request.

The Canvas editor fetches the endpoint in the browser so it can reach local
frontends. First verify the unauthenticated response from the shell:

```bash
# 401 without an assertion:
curl -i http://localhost:3000/api/canvas/components
```

Then exercise the authenticated CORS request in the Drupal origin's browser
console:

```js
// Run this in the Drupal origin's browser console, where the session cookie
// lives.
const csrf = await (await fetch('/session/token')).text();
const { assertion } = await (
  await fetch('/canvas-headless/assertion?path=/', {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrf },
  })
).json();
const registry = await (
  await fetch('http://localhost:3000/api/canvas/components', {
    headers: { Authorization: `Bearer ${assertion}` },
  })
).json();
console.log(registry);
```
