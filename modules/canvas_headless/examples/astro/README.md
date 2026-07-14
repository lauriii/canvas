# Canvas Headless example app (Astro)

An Astro app the `canvas_headless` module embeds in the Canvas editor
frame — the Astro counterpart of the sibling `next`, demonstrating
the module's draft-preview authentication end to end:

- Draft mode activates by exchanging a Drupal-signed, single-use preview
  assertion (RFC 7523 JWT-bearer grant) for an access token bound to the
  initiating editor. No client secret anywhere.
- The front page lists articles and Canvas pages via JSON:API (draft
  sessions see working copies).
- Every other path resolves through Drupal's routing via the SDK's
  `fetchPage()` and renders the returned data.
- The session renews in place over an origin-checked postMessage protocol
  with the embedding Canvas editor. Astro has no Next.js-style server
  refresh, so the SDK's `<canvas-draft-session>` element re-arms from the
  renew endpoint's `{tokenExpiresAt}` answer — no document reload.
- Renewal is PKCE-bound to the app server: an injected script can
  intercept a relayed assertion and still get no token.
- Exiting draft mode is a `POST`, submitted by a form in the banner: it
  clears the session cookies, and a `GET` link to it would be eligible for
  prefetching.
- `/api/canvas/components` exposes the app's component registry (one demo
  component, `components/canvas/hello-card` — an `.astro` entry) to the
  embedding Drupal Canvas site, protected by proof-by-redemption.

The Drupal Canvas Headless SDK lives in the workspace packages
`@drupal-canvas/headless` (framework-agnostic core) and
`@drupal-canvas/headless-astro` (the Astro adapter); this app consumes
them and keeps only the wiring: the `canvas()` integration in
`astro.config.mjs` (which injects all the API routes — no route files in
this app), the banner markup (`src/components/DraftBanner.astro`), and
rendering.

## Setup

The packages arrive as `file:` links into this repository, as raw
TypeScript compiled by the app's build (the integration adds them to
Vite's `ssr.noExternal`). Their own dependencies resolve through the
Canvas repository's workspace install, so **run `npm install` at the
Canvas repository root first**, then:

```bash
cp .env.example .env    # defaults match the canvas-env DDEV setup
npm install
npm run dev             # http://localhost:4321
```

`DRUPAL_BASE_URL` uses DDEV's http URL because Node.js does not trust
DDEV's mkcert certificate by default. To use the https URL instead, run
with `NODE_EXTRA_CA_CERTS="$(mkcert -CAROOT)/rootCA.pem"`.

Point the `canvas_headless` module's `frontend_url` at this app's dev
server (`http://localhost:4321`; the module's default port 3000 is the
Next.js example's). Embedded draft mode relies on CHIPS partitioned
cookies; on a plain-http localhost origin that works in Chromium-based
browsers (localhost is a trustworthy origin).

The production build runs the standalone Node adapter:

```bash
npm run build
node ./dist/server/entry.mjs
```

To copy this app out of the repository, replace the `file:` specifiers in
package.json with published package versions (the packages are not yet
published).

## The component metadata endpoint

`GET /api/canvas/components` answers the component registry: every
component under `components/canvas/` (set in `canvas.config.json`) with a
`component.yml`, in a versioned JSON envelope. Drupal Canvas reads it to
register the app's components.

- **Auth**: the caller presents a Drupal-minted preview assertion as a
  Bearer token; the app verifies it by redeeming it at Drupal's
  `/oauth/token` (proof-by-redemption — only the embedding Drupal can mint
  one). Assertions are single-use: mint a fresh one per request.
- **Build time vs request time**: the `canvas()` integration writes
  `.canvas/components.manifest.json` during `astro build`, and the
  endpoint serves that manifest in production — the registry describes the
  deployed build. In development it scans the codebase live on every
  request.

Try it against a running Drupal (the first request must come from a
session holding `access canvas headless preview`):

```bash
# 401 without an assertion:
curl -i http://localhost:4321/api/canvas/components
```

```js
// Mint an assertion and call the endpoint (from the Drupal origin's
// browser console, where the session cookie lives):
const csrf = await (await fetch('/session/token')).text();
const { assertion } = await (
  await fetch('/canvas-headless/assertion?path=/', {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrf },
  })
).json();
const registry = await (
  await fetch('http://localhost:4321/api/canvas/components', {
    headers: { Authorization: `Bearer ${assertion}` },
  })
).json();
```
