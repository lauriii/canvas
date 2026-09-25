# @drupal-canvas/headless-tanstack-start

TanStack Start adapter for the Drupal Canvas Headless SDK.

It gives a TanStack Start app draft preview bound to the editing user, in-place
session renewal inside the Canvas editor frame, and the component metadata
endpoint Drupal Canvas registers the app's components from.

## Installation

```bash
npm install @drupal-canvas/headless-tanstack-start
```

Set the `CANVAS_SITE_URL` environment variable to your Drupal site URL.

## Usage

**1. vite.config.ts** — the `canvas()` plugin compiles the SDK packages into the
SSR build and writes the component manifest at build time:

```ts
import { canvas } from '@drupal-canvas/headless-tanstack-start/vite';

export default defineConfig({
  plugins: [canvas(), tanstackStart(), viteReact()],
});
```

**2. Route files** — mount the handler factories in small route files:

```ts
// src/routes/api/draft.ts
import { createDraftRouteHandlers } from '@drupal-canvas/headless-tanstack-start';
import { createFileRoute } from '@tanstack/react-router';

const { draft } = createDraftRouteHandlers();
export const Route = createFileRoute('/api/draft')({
  server: { handlers: { GET: draft.GET } },
});

// src/routes/api/draft.renew.ts     -> draftRenew.POST
// src/routes/api/disable-draft.ts   -> disableDraft.POST
// src/routes/api/canvas.components.ts:
//   const { GET, OPTIONS } = createComponentMetadataHandlers();
// src/routes/api/canvas.jsonapi.$.ts (the same-origin JSON:API proxy):
//   const { jsonApiProxy } = createDraftRouteHandlers();
//   export const Route = createFileRoute('/api/canvas/jsonapi/$')({
//     server: { handlers: jsonApiProxy },
//   });
```

Mount the component-preview route:

```tsx
// src/routes/api/canvas.component-preview.tsx
import ComponentPreview, {
  loadComponentPreview,
} from '@drupal-canvas/headless-tanstack-start/ComponentPreview';
import { createFileRoute, notFound } from '@tanstack/react-router';

export const Route = createFileRoute('/api/canvas/component-preview')({
  loader: async () => (await loadComponentPreview()) ?? notFound(),
  component: () => <ComponentPreview {...Route.useLoaderData()} />,
});
```

**3. src/start.ts** — the session-aware CSP `frame-ancestors` middleware:

```ts
import { cspMiddleware } from '@drupal-canvas/headless-tanstack-start/middleware';
import { createStart } from '@tanstack/react-start';

export const startInstance = createStart(() => ({
  requestMiddleware: [cspMiddleware],
}));
```

**4. Session banner** — a server function gathers the session state
(`isDraftModeEnabled()`, `getDraftData()`, `getDraftEditorOrigin()`,
`isDraftSessionExpired()`), the root route's loader calls it, and the root
component renders `<DraftSession>` from
`@drupal-canvas/headless-tanstack-start/client` with a render prop that owns the
banner markup.

**5. Component tree** — pass the structured content and context returned by
`fetchPage()` to `<CanvasComponentTree>`:

```tsx
import { CanvasComponentTree } from '@drupal-canvas/headless-tanstack-start/CanvasComponentTree';

<CanvasComponentTree tree={page.content} context={page.context} />;
```

`context` lets registered components read the page and site data through
`usePageContext()` and `useSiteContext()` from `drupal-canvas/react`. For
`useJsonApiClient()`, read `getJsonApiRuntimeConfig()` in the server function
the root route's loader calls (alongside the session state) and render
`JsonApiRuntimeProvider` from `@drupal-canvas/headless-react` around the outlet;
`CanvasComponentTree` also accepts it as an explicit `jsonApi` prop. The
configuration is serializable and carries no credentials.

During server rendering `useJsonApiClient()` returns the same draft-aware client
as in the browser, so SWR fallback data supplied through `SWRConfig` renders
into the initial HTML and hydration does not mismatch, but the client performs
no network requests on the server: prefetch draft data with `getClient()` in the
server function the route loader calls and pass it as SWR fallback data (see
`@drupal-canvas/headless-react`).

The `canvas()` plugin supplies a registry of every discovered component
implementation, and the renderer consumes it automatically. During development
the registry updates when components are added, removed, or renamed.

## Editor origins and CSP

By default, `frame-ancestors` admits `'self'`, the `CANVAS_SITE_URL` origin and
the draft-session editor origin. Set `CANVAS_EDITOR_ORIGINS` to a comma- or
whitespace-separated list of HTTP(S) URLs to replace both defaults. An empty or
entirely invalid list admits only `'self'`. Origins are normalized and
deduplicated; credentials, wildcards and literal IPv6 are rejected. For IPv6,
use a DNS hostname.

Keep the global middleware mounted on document/preview routes. It merges CSP
after the handler chain, preserving other directives and application-owned
`frame-ancestors`. Use server-rendered previews. Reconcile later
response/hosting CSP separately: multiple policies intersect. Verify deployed
headers. This policy controls embedding, not draft authorization.

Both variables are read from server `process.env` per response. The Vite plugin
loads `.env` during dev/build, with process values taking precedence; restart
dev after edits. Supply production environment values separately and restart the
server after changes. Rebuild/redeploy if the bundler or host embeds them.

## Data access

`getClient()` returns the draft-aware JSON:API client; `fetchPage()` fetches
Canvas-rendered content when available, plus route and document-head data, for a
path resolved through Drupal routing. Both are draft-session-aware and
server-only — call them inside `createServerFn` handlers, never in isomorphic
loaders directly. Render `page.content` directly and return
`toTanStackHead(page.head)` from the route's `head` callback. Handle
`PageRedirect` in the loader with TanStack Router's `redirect()`.

The client's JSON:API prefix is resolved from the site's public site-data
endpoint (fetched once per server instance), so sites serving JSON:API from a
non-default prefix (e.g. `/api`) work without configuration. When that endpoint
is unreachable, the `CANVAS_JSONAPI_PREFIX` environment variable applies, then
the `/jsonapi` default; `CANVAS_JSONAPI_URL` sets a full upstream URL that takes
precedence over discovery. `getPublicClient()` and `getDraftClient()` are async
for the same reason: `await` them like `getClient()`. All three create the
shared `drupal-canvas` client (`createJsonApiClient()`), which deserializes
responses with `DefaultSerializer`. `getJsonApiRuntimeConfig()` and
`handleJsonApiProxy()` back the browser client and the proxy route.

`fetchEntity({ type, id, viewMode })` renders one content entity without
page-level route or head data. Use it for embedded renders such as teaser cards.
