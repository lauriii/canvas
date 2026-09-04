# @drupal-canvas/headless-next

Next.js adapter for the Drupal Canvas Headless SDK.

It gives a Next.js app draft preview bound to the editing user, in-place session
renewal inside the Canvas editor frame, and the component metadata endpoint
Drupal Canvas registers the app's components from.

## Installation

```bash
npm install @drupal-canvas/headless-next
```

Set the `CANVAS_SITE_URL` environment variable to your Drupal site URL.

## Usage

**1. next.config.ts** — the config wrapper generates the component manifest at
build time, adds the SDK packages to `transpilePackages`, and sends a
session-aware CSP `frame-ancestors` header:

```ts
import { withCanvas } from '@drupal-canvas/headless-next/config';

export default withCanvas();
```

**2. Route files** — mount the handlers, one file per route:

```ts
// app/api/draft/route.ts
import { createDraftRouteHandlers } from '@drupal-canvas/headless-next';

export const GET = createDraftRouteHandlers().draft.GET;
```

```ts
// app/api/draft/renew/route.ts
import { createDraftRouteHandlers } from '@drupal-canvas/headless-next';

export const POST = createDraftRouteHandlers().draftRenew.POST;
```

```ts
// app/api/disable-draft/route.ts
import { createDraftRouteHandlers } from '@drupal-canvas/headless-next';

export const POST = createDraftRouteHandlers().disableDraft.POST;
```

```ts
// app/api/canvas/components/route.ts
import { createComponentMetadataHandler } from '@drupal-canvas/headless-next';

export const runtime = 'nodejs';
export const dynamic = 'force-dynamic';
export const { GET, OPTIONS } = createComponentMetadataHandler();
```

```tsx
// app/api/canvas/component-preview/page.tsx
export { default } from '@drupal-canvas/headless-next/ComponentPreviewPage';
```

**3. Session banner** — a server component gathers the session state
(`getDraftData()`, `getDraftEditorOrigin()`, `isDraftSessionExpired()`) and
renders `<DraftSession>` from `@drupal-canvas/headless-next/client` with a
render prop that owns the banner markup.

**4. Component tree** — pass the structured content returned by `fetchPage()` to
`<CanvasComponentTree>`:

```tsx
import { CanvasComponentTree } from '@drupal-canvas/headless-next/CanvasComponentTree';

<CanvasComponentTree tree={page.content} />;
```

`withCanvas()` generates a registry of every discovered component
implementation, and the renderer consumes it automatically. During development
the registry updates when components are added, removed, or renamed.

## Static and hybrid builds

For a hybrid build where the Canvas-routed pages are prerendered while the draft
and editor routes stay server-rendered, edit the catch-all page in two places:
remove its `export const dynamic = 'force-dynamic'`, and add
`generateStaticParams()` fed by the enumeration helper:

```ts
import {
  fetchStaticPaths,
  resolveDraftConfig,
} from '@drupal-canvas/headless/server';

export async function generateStaticParams() {
  const paths = await fetchStaticPaths(resolveDraftConfig());
  return paths.map((path) => ({ slug: path.split('/').filter(Boolean) }));
}
```

A fully static `output: 'export'` build additionally requires passing
`cache: 'force-cache'` to `fetchPage()` (the default `no-store` fails the export
build) and removing the editor integration routes — the draft flows, the
component metadata endpoint, and the component preview page. Draft preview then
runs against a server-rendered deployment of the same app.

## Data access

`getClient()` returns the draft-aware JSON:API client; `fetchPage()` fetches
Canvas-rendered content when available, plus route and document-head data, for a
path resolved through Drupal routing. Both are draft-session-aware. Render
`page.content` directly. Use `toNextMetadata(page.head)` from
`@drupal-canvas/headless-next` in `generateMetadata()`. Handle `PageRedirect`
before page rendering with `permanentRedirect()` for permanent redirects and
`redirect()` for other redirects.

`toNextMetadata()` maps the Canvas head entries that Next.js Metadata can
represent. It omits entries that Next.js Metadata cannot represent. Render
omitted entries as native head elements in the page or layout.
