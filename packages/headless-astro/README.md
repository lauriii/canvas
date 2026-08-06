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
