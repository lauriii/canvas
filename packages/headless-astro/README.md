# @drupal-canvas/headless-astro

Astro adapter for the Drupal Canvas Headless SDK.

It gives an Astro app draft preview bound to the editing user, in-place session
renewal inside the Canvas editor frame, and the component metadata endpoint
Drupal Canvas registers the app's components from.

Draft preview needs per-request rendering, so the app needs an SSR adapter
(`@astrojs/node` or equivalent), and pages that show draft content must not be
prerendered.

## Installation

```bash
npm install @drupal-canvas/headless-astro
```

Set the `CANVAS_SITE_URL` environment variable to your Drupal site URL.

## Usage

**1. astro.config.mjs** — the integration injects the draft routes and the
component metadata endpoint, registers the CSP `frame-ancestors` middleware,
bundles the SDK packages into the SSR build, and writes the component manifest
at build time:

```js
import { defineConfig } from 'astro/config';
import node from '@astrojs/node';
import canvas from '@drupal-canvas/headless-astro/integration';

export default defineConfig({
  output: 'server',
  adapter: node({ mode: 'standalone' }),
  integrations: [canvas()],
});
```

Pass `injectRoutes: false` to mount the `routes/*` subpath exports at paths of
your own.

**2. Session banner** — render `DraftSession.astro` in the app layout with the
banner markup in its slot. The component gathers the session state server-side
and runs the renewal protocol; it owns the visibility of the marked children:

```astro
---
import DraftSession from '@drupal-canvas/headless-astro/DraftSession.astro';
---

<DraftSession>
  <div data-draft-session-view="active">Draft mode is active.</div>
  <div data-draft-session-view="expired">
    Draft session expired.
    <a data-draft-session-renew-link>Renew session</a>
  </div>
</DraftSession>
```

**3. Component tree** — pass the structured content returned by `fetchPage()` to
`CanvasComponentTree.astro`:

```astro
---
import CanvasComponentTree from '@drupal-canvas/headless-astro/CanvasComponentTree.astro';
---

<CanvasComponentTree tree={page.content} />
```

The integration supplies a registry of every discovered component
implementation, and the renderer consumes it automatically. During development
the registry updates when components are added, removed, or renamed.

## Data access

`getClient(Astro)` returns the draft-aware JSON:API client;
`fetchPage(Astro, path)` fetches Canvas-rendered content when available, plus
route and document-head data, for a path resolved through Drupal routing. Both
are draft-session-aware. Render `page.content` directly and render `page.head`
with the application's head manager. Its shape is directly compatible with
[Unhead](https://unhead.unjs.io/). Handle `PageRedirect` before page rendering
with `Astro.redirect(redirect.url, redirect.statusCode)`. Every accessor takes
the `Astro` global (pages, components) or the APIContext (endpoints,
middleware), because Astro exposes cookies per request rather than through
request-scoped globals.

## Content invalidation

When an editor publishes in Drupal, the site sends a signed publish webhook
carrying the Drupal cache tags the publish invalidated, indirect dependencies
included. The adapter ships the verified webhook plumbing so the app can turn a
publish into cache invalidation.

Astro is different from Next.js here, and the difference matters. Astro has no
framework-native cache-tag or revalidation API. On-demand revalidation is
entirely host-specific, so the adapter cannot ship a turnkey portable
revalidation route. What it ships is `createRevalidateApiRoute`, which verifies
the webhook and hands the parsed payload to a `revalidate` callback you supply.
That callback is where the host wiring goes.

**1. Mount the route** at `src/pages/api/canvas/revalidate.ts`:

```ts
import { createRevalidateApiRoute } from '@drupal-canvas/headless-astro';

export const prerender = false;
export const { POST } = createRevalidateApiRoute({
  revalidate: async (payload) => {
    // Host-specific invalidation, keyed off payload.tags.
  },
});
```

The `prerender = false` export is required. The route must run per request.

**2. Set the secret.** The handler verifies the `X-Canvas-Signature` HMAC
against `CANVAS_PUBLISH_WEBHOOK_SECRET`, which must match the site's
`$settings['canvas_headless_publish_webhook_secret']`. Set it in your `.env`
(the integration bridges it into `process.env`) or in the deployment
environment, or pass it explicitly as the `secret` option. A missing signature
answers 401; a missing secret answers 500. If no `revalidate` callback is
supplied the route answers 501, because there is nothing portable to fall back
on.

**3. Wire the `revalidate` callback to your host.** `payload.tags` holds the
Drupal cache tags the publish invalidated. Map them to whatever your host
exposes:

- **Vercel** (`@astrojs/vercel`): purge with a bypass token, or fetch the
  affected paths with the bypass header set so the edge cache refreshes.
- **Netlify** (`@astrojs/netlify`): use Netlify's on-demand purge for the
  affected paths or tags.
- **Bun** (`@astrojs/bun`): call the adapter's `unstable_expirePath` for each
  affected path.
- **Pure-static output**: there is no runtime cache to purge, so invalidation
  means a rebuild. Trigger your CI deploy hook from the callback (for example
  `await fetch(process.env.DEPLOY_HOOK_URL, { method: 'POST' })`). The rebuild
  fetches fresh content from Drupal and redeploys.

### Purging a CDN by surrogate key

If a CDN fronts the deploy, emit a `Surrogate-Key` header on each page response
from the page's cacheability, so the CDN can purge by key when the webhook
arrives. In the catch-all page, once you have a rendered page (a `PageResult`
that is not a redirect):

```astro
---
import {
  fetchPage,
  isPageRedirect,
  surrogateKeyHeader,
} from '@drupal-canvas/headless-astro';

const result = await fetchPage(Astro, Astro.url.pathname);
if (result && isPageRedirect(result)) {
  return Astro.redirect(result.redirect.url, result.redirect.statusCode);
}
if (result) {
  Astro.response.headers.set('Surrogate-Key', surrogateKeyHeader(result));
}
---
```

`surrogateKeyHeader(page)` is the space-joined `page.cacheability.tags`. The
publish webhook's `payload.tags` are the same Drupal cache tags, so the
`revalidate` callback can issue a key-based purge for exactly the pages a
publish touched.
