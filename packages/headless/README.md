# @drupal-canvas/headless

Framework-agnostic core of the Drupal Canvas Headless SDK: draft preview
sessions, draft-aware content clients, and component metadata exposure for
decoupled frontend apps.

The Canvas Headless module lets the Drupal Canvas editor embed your frontend
app, so editors preview their work — draft content included — rendered by the
app itself, with the app's components registered in Canvas. Draft previews
include the markers and geometry Canvas needs for selection and drag-and-drop,
while published pages keep normal application markup.

This package is the framework-neutral app side of that integration. Most apps
use a framework adapter instead of this package directly:

- `@drupal-canvas/headless-next` (Next.js)
- `@drupal-canvas/headless-astro` (Astro)
- `@drupal-canvas/headless-nuxt` (Nuxt)
- `@drupal-canvas/headless-tanstack-start` (TanStack Start)
- `@drupal-canvas/headless-angular` (Angular)

## Editor origins and CSP

All adapters use the same `frame-ancestors` resolver:

| `CANVAS_EDITOR_ORIGINS`                  | Sources (when the app does not already set `frame-ancestors`)                   |
| ---------------------------------------- | ------------------------------------------------------------------------------- |
| Unset                                    | `'self'` + `CANVAS_SITE_URL` origin + draft-session editor origin, when present |
| Set                                      | `'self'` + valid origins from this setting only                                 |
| Set to an empty or entirely invalid list | `'self'` only; neither default is restored                                      |

`CANVAS_EDITOR_ORIGINS` is a whitespace- or comma-separated list, for example
`https://cms.example, https://staging.example:8443`. Values are normalized to
scheme, host and port, and deduplicated. Only HTTP(S) URLs without credentials
and with concrete DNS hostnames or IPv4 literals are accepted. Wildcards, policy
delimiters in hosts and literal IPv6 addresses are rejected. For IPv6, use a DNS
hostname; hostnames resolving to IPv6 remain supported. The same validation
applies to both default origins.

The adapters preserve all policies already present on the response and leave an
application-owned `frame-ancestors` authoritative. Multiple CSP policies
intersect; adding another header cannot relax an existing restriction. Reconcile
any separately configured CDN/hosting CSP with the application policy.

The resolver reads server `process.env` on every call, not from public/client
configuration. Environment delivery depends on the framework and deployment: see
the adapter README. Restart a running server after changing its environment;
rebuild/redeploy when configuration is embedded by the bundler or host. Changing
an environment file alone does not update an already running production server.

This is an embedding policy, **not authorization**. Draft authentication, token
exchange, PKCE, editor identity pinning and postMessage checks are unchanged.

## Rendered pages

`fetchPage()` asks Drupal to resolve a site-relative path. Drupal returns route
context and document-head data, plus a Canvas component tree when Canvas renders
the route:

```ts
import { isPageRedirect } from '@drupal-canvas/headless';

const result = await server.fetchPage('/articles/hello-world');
if (result && isPageRedirect(result)) {
  // Propagate result.redirect.url through the framework router.
} else {
  result?.head.title;
  result?.content;
  result?.route.managedByCanvas;
  result?.route.entity;
}
```

Pass `page.content` directly to the framework's `CanvasComponentTree` renderer.
It contains one structured root, or `null` when Canvas does not return managed
content. Multiple rendered roots are nested under a transparent structural root.
Pass `page.head` to the framework bridge documented by the adapter.

When Drupal resolves a configured redirect, `fetchPage()` returns `PageRedirect`
instead of `Page`. It contains `redirect.url`, `redirect.external`, and Drupal's
configured `redirect.statusCode`. Handle it before reading page fields using the
framework's redirect primitive.

During an authorized draft session, the same call uses available content drafts.
Public calls use stored content.

`page.route.negotiatedLanguage` identifies the negotiated content language.
`page.route.translations` lists every enabled language as
`{ langcode, name, nativeName, url, translationAvailable, current, external }`.
Names, availability, requested-language `current`, and fallback URL semantics
match Code Components' `getPageData().mainEntity.translations`. Missing and
denied translations both report `translationAvailable: false`; their URLs do not
guarantee access. `route.entity.langcode` remains the rendered language, which
can differ from the negotiated language on fallback. Monolingual sites and
routes without a canonical content entity return an empty list.

Non-external URLs are site-relative Drupal request URIs without the installation
base path. For available translations, pass them unchanged to `fetchPage`,
preserving language prefixes and query strings. External URLs remain absolute
and are not valid `fetchPage` input; the SDK does not thereby support Drupal
domain negotiation. Map entries to public URLs in the frontend. See the
[multilingual guide](../../docs/user/src/content/docs/headless/multilingual-sites.mdx#translation-links)
for a language-switcher example.

Use `fetchEntity({ type, id, viewMode })` when the current request should render
one specific content entity in a specific content-template view mode, such as
`server.fetchEntity({ type: 'node', id: '1', viewMode: 'teaser' })`.

Routes that Canvas does not render still return their document-head and route
data with `content` set to `null` and `route.managedByCanvas` set to `false`. An
empty managed tree also has `content` set to `null`, but keeps
`route.managedByCanvas` set to `true`. Route-not-found and access-denied
responses make `fetchPage()` return `null`.

## Installation

```bash
npm install @drupal-canvas/headless
```

Type-checking with `skipLibCheck: false` can surface errors from a transitive
dependency's type declarations (`jsona`, via the JSON:API client); the
`skipLibCheck: true` default of framework tsconfigs avoids them.

## Entry points

The subpaths keep browser bundles free of Node-only code and vice versa:

- `@drupal-canvas/headless` — isomorphic: protocol constants, the `DraftData`
  session contract, rendered-page types, `isPageRedirect()`, and JSON script
  serialization.
- `@drupal-canvas/headless/client` — browser-only: the draft session state
  machine, the `<canvas-draft-session>` element, and preview geometry helpers.
- `@drupal-canvas/headless/server` — server-side, edge-safe: the draft server
  with its activation, renewal, and exit flows, the draft-aware content clients,
  and CSP helpers.
- `@drupal-canvas/headless/components-endpoint` — Node-only: the component
  metadata endpoint handler and the build-time component manifest.
- `@drupal-canvas/headless/component-registry` — Node-only: generates component
  implementation registry source.
- `@drupal-canvas/headless/vite` — Node-only: the shared component registry
  plugin for adapters built on Vite.
- `@drupal-canvas/headless/preview.css` — styles empty slot and region drop
  targets in draft previews.
- `@drupal-canvas/headless/node` — Node-only: system certificate configuration
  for HTTPS requests to services using certificates trusted by the operating
  system, including local DDEV sites.

## System certificates (Node.js only)

To trust DDEV and other system certificates, call the `trustSystemCertificates`
utility before making HTTPS requests:

```ts
import { trustSystemCertificates } from '@drupal-canvas/headless/node';

trustSystemCertificates();
```

## Writing a framework adapter

Use an existing adapter if one exists for your framework. Writing a new one is
mostly wiring:

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

   In draft mode, the renderer must emit Canvas boundaries and load
   `@drupal-canvas/headless/preview.css` to give empty slots and regions
   measurable geometry for Canvas UI overlays. A draft `full` view-mode tree
   with page variant chrome places its editable region inside the transparent
   `CANVAS_PREVIEW_CONTENT_REGION_ELEMENT` (`canvas-preview-content-region`).
   Render that element's slot children without a DOM wrapper and surround them
   with the `content` region boundaries. When it has no content, include an
   element with `CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS` so Canvas can measure
   the region for its drop-target overlay. Before adding top-level `content`
   boundaries, use `hasCanvasPreviewContentRegion()` to avoid emitting a second
   pair.

5. Wire the client side: render the `<canvas-draft-session>` element, or the
   React `<DraftSession>` from `@drupal-canvas/headless-react`, with the session
   state your server gathered.

   To refresh after Canvas auto-saves without reloading the document, pass
   `refreshData` to React's `DraftSession`, or handle
   `DRAFT_SESSION_REFRESH_EVENT` when using `<canvas-draft-session>`.

6. Expose data access: `server.getClient()` and `server.fetchPage()`, surfaced
   however your framework reaches per-request state.
