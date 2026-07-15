# @drupal-canvas/headless-astro

Astro adapter for the Drupal Canvas Headless SDK (`@drupal-canvas/headless`):
draft preview bound to the editing user, in-place session renewal inside the
Canvas editor frame, and the component metadata endpoint Drupal Canvas registers
the app's components from.

Draft preview needs per-request rendering, so the app needs an SSR adapter
(`@astrojs/node` or equivalent). Pages that show draft content must not be
prerendered; the SDK's own routes opt out of prerendering themselves.

## Setup

Three pieces, all wiring:

**1. astro.config.mjs** — the integration injects the draft routes
(`/api/draft`, `/api/draft/renew`, `/api/disable-draft`) and the component
metadata endpoint (`/api/canvas/components`), registers the CSP
`frame-ancestors` middleware, bundles the raw-TypeScript SDK packages into the
SSR build, bridges the SDK's `.env` keys into `process.env`, and writes the
component manifest at build time:

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

(Pass `injectRoutes: false` to mount the `routes/*` subpath exports at paths of
your own.)

**2. The session banner** — render `DraftSession.astro` in the app layout with
the banner markup in its slot. The component gathers the session state
server-side and drives the framework-free `<canvas-draft-session>` element from
`@drupal-canvas/headless/client`, which runs the renewal protocol and owns the
visibility of the marked children:

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

**3. The component tree renderer** — pass the structured content returned by
`fetchPage()` to `CanvasComponentTree.astro`:

```astro
---
import CanvasComponentTree from '@drupal-canvas/headless-astro/CanvasComponentTree.astro';
---

<CanvasComponentTree tree={page.content} />
```

The Astro integration supplies every discovered component implementation through
the shared headless Vite registry, and the renderer consumes it automatically.
During development, the registry updates when components are added, removed, or
renamed, so the application does not maintain a registry manually.

Environment: `CANVAS_SITE_URL` (required).

The CSP is `'self'`-only without a draft session. During a draft session, it
also admits the exact editor origin derived from the signed renewal URL. The
same origin is the only `postMessage` peer. An application-defined
`frame-ancestors` directive remains authoritative.

Data access from app code: `getClient(Astro)` (draft-aware JSON:API client) and
`fetchPage(Astro, path)` (rendered content, resolved through Drupal's routing),
both draft-session-aware. Astro exposes cookies per request rather than through
request-scoped globals, so every accessor takes the `Astro` global (pages,
components) or the APIContext (endpoints, middleware).

## Renewal without a server refresh

Next.js refreshes the server tree after a renewal (`router.refresh()`); Astro's
multi-page model has no equivalent. The `<canvas-draft-session>` element instead
re-arms in place from the renew endpoint's `{tokenExpiresAt}` answer — no
document reload, no navigation loss. The renewed token already lives in the
session cookie, so the next request carries it regardless.

## Draft mode without a framework draft mode

Astro has no built-in preview flag, so the SDK's Astro adapter keeps its own
flag cookie (`canvas_headless_draft_mode`), set and cleared with the same
cross-site (CHIPS) attributes as the session data cookie. With every page
rendered on demand there is no prerender cache to bypass; the flag only records
that a draft session was activated and not yet exited.

## The component metadata endpoint

`GET /api/canvas/components` answers the codebase's component registry (every
`component.yml` under the `canvas.config.json` `componentDir`) in a versioned
envelope; see `@drupal-canvas/headless/components-endpoint` for the payload
shape and the proof-by-redemption protection. Astro components (`.astro`
entries) are as discoverable as React ones — the registry carries metadata only,
and the app renders its own components.

In production the endpoint serves the manifest the integration wrote at
`astro build` (component sources are typically absent at runtime, and the
registry should describe the deployed build); in development it scans live, so a
new component is visible on the next fetch.
