# @drupal-canvas/headless-nuxt

Nuxt adapter for the Drupal Canvas Headless SDK (`@drupal-canvas/headless`):
draft preview bound to the editing user, in-place session renewal inside the
Canvas editor frame, and the component metadata endpoint Drupal Canvas registers
the app's components from.

## Setup

Three pieces, all wiring:

**1. nuxt.config.ts** — the module mounts the draft routes (`/api/draft`,
`/api/draft/renew`, `/api/disable-draft`, `/api/draft/session`) and the
component metadata endpoint (`/api/canvas/components`), registers the CSP
`frame-ancestors` middleware, compiles the raw-TypeScript SDK packages into both
the Vue and Nitro builds, and writes the component manifest at build time:

```ts
export default defineNuxtConfig({
  modules: ['@drupal-canvas/headless-nuxt'],
});
```

(Configure under the `drupalCanvas` key: `injectRoutes: false` to mount the
runtime handlers at paths of your own, `componentsRoutePath` to move the
metadata endpoint.)

**2. The session banner** — render the globally registered `<DraftSession>`
component in the app shell with the banner markup in its slot. The component
fetches the session state from `/api/draft/session` (in-process during SSR) and
drives the framework-free `<canvas-draft-session>` element from
`@drupal-canvas/headless/client`, which runs the renewal protocol and owns the
visibility of the marked children:

```vue
<DraftSession>
  <div data-draft-session-view="active">Draft mode is active.</div>
  <div data-draft-session-view="expired">
    Draft session expired.
    <a data-draft-session-renew-link>Renew session</a>
  </div>
</DraftSession>
```

**3. The component tree renderer** — pass the structured content returned by
`fetchPage()` to the globally registered `<CanvasComponentTree>`:

```vue
<CanvasComponentTree :tree="page.content" />
```

The Nuxt module supplies every discovered component implementation through the
shared headless Vite registry, and the renderer consumes it automatically.
During development, the registry updates when components are added, removed, or
renamed, so the application does not maintain a registry manually.

Environment: `CANVAS_SITE_URL` (required). The development server fails at
startup when it is missing.

The CSP is `'self'`-only without a draft session. During a draft session, it
also admits the exact editor origin derived from the signed renewal URL. The
same origin is the only `postMessage` peer. An application-defined
`frame-ancestors` directive remains authoritative.

Data access from app code happens in Nitro server routes, where the draft
session cookies live: `getClient(event)` (draft-aware JSON:API client) and
`fetchPage(event, path)` (rendered content, resolved through Drupal's routing)
from `@drupal-canvas/headless-nuxt/server`, both draft-session-aware. Pages
consume those routes with `useFetch()`, which forwards the request's cookies
during SSR.

## Renewal without a server refresh

Next.js refreshes the server tree after a renewal (`router.refresh()`); a
Nitro-rendered page has no equivalent. The `<canvas-draft-session>` element
instead re-arms in place from the renew endpoint's `{tokenExpiresAt}` answer —
no document reload, no navigation loss. The renewed token already lives in the
session cookie, so the next request carries it regardless.

## Draft mode without a framework draft mode

Nuxt has no built-in preview flag scoped to this protocol, so the SDK's adapter
keeps its own flag cookie (`canvas_headless_draft_mode`), set and cleared with
the same cross-site (CHIPS) attributes as the session data cookie.

## The component metadata endpoint

`GET /api/canvas/components` answers the codebase's component registry (every
`component.yml` under the `canvas.config.json` `componentDir`) in a versioned
envelope; see `@drupal-canvas/headless/components-endpoint` for the payload
shape and the proof-by-redemption protection. Vue components (`.vue` entries)
are as discoverable as React ones — the registry carries metadata only, and the
app renders its own components.

Drupal coordinates the request in the editor's browser so it can reach local
frontends. `OPTIONS` allows the authorization preflight, and the authenticated
response is exposed only to the editor origin carried in the assertion's signed
renewal URL.

In production the endpoint serves the manifest the module wrote at `nuxt build`
(component sources are typically absent at runtime, and the registry should
describe the deployed build); in development it scans live, so a new component
is visible on the next fetch.
