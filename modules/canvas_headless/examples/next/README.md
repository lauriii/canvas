# Canvas Headless example app

A Next.js app the `canvas_headless` module embeds in the Canvas editor
frame. It demonstrates the module's draft-preview authentication end to
end:

- Draft mode activates by exchanging a Drupal-signed, single-use preview
  assertion (RFC 7523 JWT-bearer grant) for an access token bound to the
  initiating editor. No client secret anywhere.
- The front page lists articles and Canvas pages via JSON:API (draft
  sessions see working copies).
- Every other path resolves through Drupal's routing via the SDK's
  `fetchPage()`. `<CanvasComponentTree>` recursively renders the returned tree
  using every local component implementation discovered by the SDK. Adding or
  removing a component updates the registry without restarting `next dev`.
- The session renews in place over an origin-checked postMessage protocol
  with the embedding Canvas editor; see the Canvas UI's
  `useHeadlessDraftSession` hook for the other side.
- Renewal is PKCE-bound to the app server. A relayed assertion reaches the
  app through the page's script context, so Drupal will not redeem one
  without a `code_verifier` the server registered at the previous exchange
  and keeps in the `httpOnly` session cookie. An injected script can
  intercept the assertion and still get no token.
- Exiting draft mode is a `POST` (`app/api/disable-draft/route.ts`),
  submitted by a form in the banner: it clears the session cookies, and a
  `GET` link to it would be eligible for prefetching.
- `/api/canvas/components` exposes the app's component registry (every
  component under `components/canvas`) to the embedding Drupal
  Canvas site, protected by proof-by-redemption.

The Drupal Canvas Headless SDK lives in the workspace packages
`@drupal-canvas/headless` (framework-agnostic core) and
`@drupal-canvas/headless-next` (the Next.js adapter); this app consumes
them and keeps only the wiring: route mounting (`app/api/`), the banner UI
(`components/draft-banner.tsx`), and rendering.

## Setup

The packages arrive as `file:` links into this repository, as raw
TypeScript compiled by the app's build (`transpilePackages`). Their own
dependencies resolve through the Canvas repository's workspace install, so
**run `npm install` at the Canvas repository root first**, then:

```bash
cp .env.example .env.local  # defaults match the canvas-env DDEV setup
npm install
npm run dev                 # http://localhost:3000
```

`CANVAS_SITE_URL` uses DDEV's http URL because Node.js does not trust
DDEV's mkcert certificate by default. To use the https URL instead, run
with `NODE_EXTRA_CA_CERTS="$(mkcert -CAROOT)/rootCA.pem"`.

Add `http://localhost:3000` from Canvas's **Headless frontends** screen.
Embedded draft mode relies on CHIPS partitioned cookies; on a plain-http
localhost origin that works in Chromium-based browsers (localhost is a
trustworthy origin).

To copy this app out of the repository, replace the `file:` specifiers in
package.json with published package versions (the packages are not yet
published), and drop the `@drupal-canvas/*` path mappings from
tsconfig.json plus the `turbopack.root` setting from next.config.ts — both
exist only for the in-repository layout.

## The component metadata endpoint

`GET /api/canvas/components` answers the component registry: every
component under `components/canvas/` (set in `canvas.config.json`) with a
`component.yml`, in a versioned JSON envelope. Drupal Canvas reads it to
register the app's components.

- **Auth**: the caller presents a Drupal-minted preview assertion as a
  Bearer token; the app verifies it by redeeming it at Drupal's
  `/oauth/token` (proof-by-redemption — only the embedding Drupal can mint
  one). Assertions are single-use: mint a fresh one per request.
- **Build time vs request time**: `withCanvas()` in next.config.ts writes
  `.canvas/components.manifest.json` during `next build`, and the endpoint
  serves that manifest in production — the registry describes the deployed
  build. In development it scans the codebase live on every request.

Drupal calls the endpoint server-to-server. For a manual check, first verify
the unauthenticated response:

```bash
# 401 without an assertion:
curl -i http://localhost:3000/api/canvas/components
```

Then mint a fresh assertion in the Drupal origin's browser console, where the
session cookie lives:

```js
const csrf = await (await fetch('/session/token')).text();
const { assertion } = await (
  await fetch('/canvas-headless/assertion?path=/', {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrf },
  })
).json();
console.log(assertion);
```

Copy it immediately into a server-side request:

```bash
curl -i -H 'Authorization: Bearer <fresh-assertion>' \
  http://localhost:3000/api/canvas/components
```
