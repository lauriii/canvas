# @drupal-canvas/headless

The framework-agnostic core of the Drupal Canvas Headless SDK.

The Canvas Headless module lets the Drupal Canvas editor embed your decoupled
frontend app, so editors preview their work rendered by the app itself — draft
content included, with the app's components registered in Canvas. This SDK is
the app side of that integration. It gives your app:

- **Draft preview**: editors opening a preview get a session bound to their own
  Drupal account, carried in secure cookies, so the app can fetch unpublished
  content exactly as that editor may see it. Sessions renew invisibly while the
  app sits inside the editor frame, and expire safely when it does not.
- **Component metadata exposure**: an authenticated endpoint answering the app's
  component registry, which the Canvas Headless module reads to register your
  components in the editor.

Most apps never use this package directly: the framework adapters —
`@drupal-canvas/headless-next` (Next.js), `@drupal-canvas/headless-astro`
(Astro), `@drupal-canvas/headless-nuxt` (Nuxt), and
`@drupal-canvas/headless-tanstack-start` (TanStack Start) — wire everything to
their framework's routing, cookies, and build pipeline, and
`@drupal-canvas/headless-react` carries the shared React `<DraftSession>`
component the React-based adapters build on. The adapters stay deliberately
thin: assertion redemption, cookie contents, claim validation, identity pinning,
the renewal protocol, and the component metadata handler all live here, so every
framework behaves identically.

## Entry points

The subpaths keep browser bundles free of Node-only code and vice versa:

- `@drupal-canvas/headless` — isomorphic, dependency-free: the protocol
  constants (the client id and the postMessage message types; the latter are the
  wire contract with the embedding editor and are re-exported by the host-side
  `@drupal-canvas/headless-host` so both sides share one source of truth), the
  `DraftData` session contract with parse/serialize/expiry helpers, assertion
  claim decoding, and the session token helper.
- `@drupal-canvas/headless/client` — browser-only: `createDraftSession()`, the
  app side of the host renewal protocol as a framework-free state machine
  (expiry timing, status reporting, origin-checked assertion handling), and the
  `<canvas-draft-session>` custom element wrapping it for consumers without a
  component runtime. The consumer owns presentation; a machine serves one
  session epoch and is replaced when a renewal delivers a new `tokenExpiresAt`.
- `@drupal-canvas/headless/server` — server-side, edge-safe (no filesystem): the
  `DraftServerAdapter` interface, `createDraftServer()` with the
  activation/renewal/exit flows, the RFC 7523 token exchange with its PKCE
  session proof (RFC 7636, binding in-place renewal to the app server), the
  draft-aware JSON:API client and rendered-content fetcher, the
  proof-by-redemption request verifier, and session-aware CSP helpers.
- `@drupal-canvas/headless/components-endpoint` — Node-only (component discovery
  reads the filesystem): the component metadata endpoint handler,
  `buildComponentMetadataPayload()` on top of `@drupal-canvas/discovery`, and
  the build-time component manifest (`writeComponentManifest()` /
  `readComponentManifest()`).
- `@drupal-canvas/headless/component-registry` — Node-only: generates static
  component implementation registry source. Framework adapters can expose it
  through a virtual module or write it to a file.
- `@drupal-canvas/headless/vite` — Node-only: shared component registry
  implementation for framework adapters using Vite. It uses a virtual module
  with automatic refreshes during development.

## Writing a framework adapter

Use an existing adapter if one exists for your framework; writing a new one is
mostly wiring. The four in this repository are worked examples of every step.

1. **Implement `DraftServerAdapter`** (from `@drupal-canvas/headless/server`):
   how your framework reads a request cookie, sets a response cookie, flips its
   draft/preview flag (or a self-managed flag cookie where the framework has
   none), and redirects. That is the whole interface.

2. **Create the draft server and mount its flows as routes**:

   ```ts
   import { createDraftServer } from '@drupal-canvas/headless/server';

   const server = createDraftServer({ adapter: myFrameworkAdapter });
   // GET  /api/draft          -> server.enableDraftMode(request)
   // POST /api/draft/renew    -> server.renewDraftSession(request)
   // POST /api/disable-draft  -> server.disableDraftMode()
   ```

   The flows take a web `Request` and answer a web `Response`, so any framework
   with standard request handling mounts them directly.

3. **Mount the component metadata endpoint**: wrap
   `createComponentMetadataHandler()` (from
   `@drupal-canvas/headless/components-endpoint`) in a route, passing your
   framework's production signal, a `loadManifest` that answers the component
   manifest your build inlined into the server bundle (a Vite virtual module,
   Nitro's `virtual`, Next.js env injection — the handler itself never touches
   the filesystem, so bundlers' file tracers have nothing to over-trace), and a
   `scanComponents` that runs `buildComponentMetadataPayload()` for
   development's live scanning.

4. **Provide the component implementation registry and tree renderer**:
   Vite-based adapters should register `canvasComponentRegistry()` from
   `@drupal-canvas/headless/vite`. It supplies
   `virtual:@drupal-canvas/headless/components` and refreshes it when components
   are added, removed, renamed, or reconfigured. Other adapters can generate
   registry source with `buildComponentRegistryModule()` from
   `@drupal-canvas/headless/component-registry`; the adapter owns where that
   source is written and how it is refreshed.

   Expose a framework-specific `CanvasComponentTree` that consumes the registry
   and recursively renders the Custom Elements tree returned by `fetchPage()`.
   It should resolve implementations by component machine name, pass props,
   render named slots recursively, and insert trusted Drupal markup nodes.

5. **Wire the client side**: render the `<canvas-draft-session>` element (or the
   React `<DraftSession>` from `@drupal-canvas/headless-react`) with the session
   state your server gathered — token expiry, the exact editor origin derived
   from the signed renewal URL, and the renewal URL itself. It runs the renewal
   protocol with the embedding editor and drives the app's session banner.

6. **Expose data access**: `server.getClient()` (draft-aware JSON:API) and
   `server.fetchPage()` (rendered content, resolved through Drupal's routing),
   surfaced however your framework reaches per-request state.

## Publishing note

The package ships raw TypeScript (`exports` point at `./src`); consumers compile
it (Next.js: `transpilePackages`). A future compiled build must preserve the
`'use client'` directive of `@drupal-canvas/headless-react` and the adapters'
client entries, and vendor the type-only `@drupal-canvas/ui` references
reachable through `@drupal-canvas/discovery`.
