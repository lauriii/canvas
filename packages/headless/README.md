# @drupal-canvas/headless

Framework-agnostic core of the Drupal Canvas Headless SDK: draft preview
sessions, draft-aware content clients, and component metadata exposure for
decoupled frontend apps.

The Canvas Headless module lets the Drupal Canvas editor embed your frontend
app, so editors preview their work — draft content included — rendered by the
app itself, with the app's components registered in Canvas. This package is the
framework-neutral app side of that integration. Most apps use a framework
adapter instead of this package directly:

- `@drupal-canvas/headless-next` (Next.js)
- `@drupal-canvas/headless-astro` (Astro)
- `@drupal-canvas/headless-nuxt` (Nuxt)
- `@drupal-canvas/headless-tanstack-start` (TanStack Start)

## Installation

```bash
npm install @drupal-canvas/headless
```

Type-checking with `skipLibCheck: false` can surface errors from a transitive
dependency's type declarations (`jsona`, via the JSON:API client); the
`skipLibCheck: true` default of framework tsconfigs avoids them.

## Entry points

The subpaths keep browser bundles free of Node-only code and vice versa:

- `@drupal-canvas/headless` — isomorphic: the protocol constants and the
  `DraftData` session contract.
- `@drupal-canvas/headless/client` — browser-only: the draft session state
  machine and the `<canvas-draft-session>` element.
- `@drupal-canvas/headless/server` — server-side, edge-safe: the draft server
  with its activation, renewal, and exit flows, the draft-aware content clients,
  and CSP helpers.
- `@drupal-canvas/headless/components-endpoint` — Node-only: the component
  metadata endpoint handler and the build-time component manifest.
- `@drupal-canvas/headless/component-registry` — Node-only: generates component
  implementation registry source.
- `@drupal-canvas/headless/vite` — Node-only: the shared component registry
  plugin for adapters built on Vite.

## Writing a framework adapter

Use an existing adapter if one exists for your framework. Writing a new one is
mostly wiring, and the adapter packages listed above are worked examples of
every step:

1. Implement `DraftServerAdapter` from `@drupal-canvas/headless/server`: how
   your framework reads and sets cookies, flips its draft or preview flag, and
   redirects.
2. Create the draft server and mount its flows as routes. The flows take a web
   `Request` and answer a web `Response`:

   ```ts
   import { createDraftServer } from '@drupal-canvas/headless/server';

   const server = createDraftServer({ adapter: myFrameworkAdapter });
   // GET  /api/draft          -> server.enableDraftMode(request)
   // POST /api/draft/renew    -> server.renewDraftSession(request)
   // POST /api/disable-draft  -> server.disableDraftMode()
   ```

3. Mount `createComponentMetadataHandler()` from
   `@drupal-canvas/headless/components-endpoint` as a route, with both its `GET`
   and `OPTIONS` handlers.
4. Provide the component implementation registry — `canvasComponentRegistry()`
   from `@drupal-canvas/headless/vite` for Vite-based frameworks, or generated
   source from `@drupal-canvas/headless/component-registry` — and expose a
   `CanvasComponentTree` renderer that consumes it.
5. Wire the client side: render the `<canvas-draft-session>` element, or the
   React `<DraftSession>` from `@drupal-canvas/headless-react`, with the session
   state your server gathered.
6. Expose data access: `server.getClient()` and `server.fetchPage()`, surfaced
   however your framework reaches per-request state.
