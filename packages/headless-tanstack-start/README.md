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

**5. Component tree** — pass the structured content returned by `fetchPage()` to
`<CanvasComponentTree>`:

```tsx
import { CanvasComponentTree } from '@drupal-canvas/headless-tanstack-start/CanvasComponentTree';

<CanvasComponentTree tree={page.content} />;
```

The `canvas()` plugin supplies a registry of every discovered component
implementation, and the renderer consumes it automatically. During development
the registry updates when components are added, removed, or renamed.

## Data access

`getClient()` returns the draft-aware JSON:API client; `fetchPage()` fetches
Canvas-rendered content when available, plus route and document-head data, for a
path resolved through Drupal routing. Both are draft-session-aware and
server-only — call them inside `createServerFn` handlers, never in isomorphic
loaders directly. Render `page.content` directly and return
`toTanStackHead(page.head)` from the route's `head` callback. Handle
`PageRedirect` in the loader with TanStack Router's `redirect()`.
