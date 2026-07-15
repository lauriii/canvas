# @drupal-canvas/headless-tanstack-start

TanStack Start adapter for the Drupal Canvas Headless SDK
(`@drupal-canvas/headless`): draft preview bound to the editing user, in-place
session renewal inside the Canvas editor frame, and the component metadata
endpoint Drupal Canvas registers the app's components from.

## Setup

Four pieces, all wiring:

**1. vite.config.ts** — the canvas() plugin compiles the raw-TypeScript SDK
packages into the SSR build, bridges the SDK's `.env` keys into `process.env`,
and writes the component manifest at build time, inlined into the server bundle:

```ts
import { canvas } from '@drupal-canvas/headless-tanstack-start/vite';

export default defineConfig({
  plugins: [canvas(), tanstackStart(), viteReact()],
});
```

**2. Route files** — TanStack Start's file-based routing has no injection
mechanism, so mount the handler factories in small route files:

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
//   const { GET } = createComponentMetadataHandlers();
```

**3. src/start.ts** — the session-aware CSP `frame-ancestors` middleware:

```ts
import { cspMiddleware } from '@drupal-canvas/headless-tanstack-start/middleware';
import { createStart } from '@tanstack/react-start';

export const startInstance = createStart(() => ({
  requestMiddleware: [cspMiddleware],
}));
```

**4. The session banner** — a server function gathers the session state
(`isDraftModeEnabled()`, `getDraftData()`, `getDraftEditorOrigin()`,
`isDraftSessionExpired()`), the root route's loader calls it, and the root
component renders `<DraftSession>` from
`@drupal-canvas/headless-tanstack-start/client` with a render prop that owns the
banner markup. The component runs the renewal protocol either way; the render
prop is optional.

Environment: `CANVAS_SITE_URL` (required).

The CSP is `'self'`-only without a draft session. During a draft session, it
also admits the exact editor origin derived from the signed renewal URL. The
same origin is the only `postMessage` peer. An application-defined
`frame-ancestors` directive remains authoritative.

Data access from app code: `getClient()` (draft-aware JSON:API client) and
`fetchPage()` (rendered content, resolved through Drupal's routing), both
draft-session-aware and server-only — call them inside `createServerFn`
handlers, never in isomorphic loaders directly.

## Renewal without a server refresh

The `<DraftSession>` here wires no `refreshData`: after a renewal the shared
React component (see `@drupal-canvas/headless-react`) re-arms in place from the
renew endpoint's `{tokenExpiresAt}` answer, independent of the app's loader
structure and caching. The renewed token already lives in the session cookie, so
loaders re-running on the next navigation carry it.

## Draft mode without a framework draft mode

TanStack Start has no built-in preview flag, so the SDK's adapter keeps its own
flag cookie (`canvas_headless_draft_mode`), set and cleared with the same
cross-site (CHIPS) attributes as the session data cookie.

## The component metadata endpoint

`GET /api/canvas/components` answers the codebase's component registry (every
`component.yml` under the `canvas.config.json` `componentDir`) in a versioned
envelope; see `@drupal-canvas/headless/components-endpoint` for the payload
shape and the proof-by-redemption protection.

In production the endpoint serves the manifest the canvas() plugin inlined into
the server bundle at `vite build` (component sources are typically absent at
runtime, and the registry should describe the deployed build); in development it
scans live, so a new component is visible on the next fetch.
