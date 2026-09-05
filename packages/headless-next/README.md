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
build time and adds the SDK packages to `transpilePackages`:

```ts
import { withCanvas } from '@drupal-canvas/headless-next/config';

export default withCanvas();
```

**2. proxy.ts** — sends the CSP `frame-ancestors` header that admits the Canvas
editor. Next.js allows one per project, so the app owns the file; put it at the
project root, or in `src/`. Before Next.js 16 the file is `middleware.ts` and
the export is `middleware`; Next.js resolves each convention by its own name.
The build warns when nothing mounts it.

```ts
// proxy.ts
export { canvasMiddleware as proxy } from '@drupal-canvas/headless-next/middleware';

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico).*)'],
};
```

Responses are `frame-ancestors 'self'`; a request carrying a draft session also
admits that session's editor origin. Set the app's own CSP directives on the
response and hand it over — they are merged, and a `frame-ancestors` directive
you set yourself stays authoritative. Configuring Content-Security-Policy in
`next.config`'s `headers()` instead is refused at build time, because hosting
platforms apply those rules after the proxy runs and replace the whole header.
Make sure `config.matcher` covers your document routes: Next.js skips the proxy
for anything it excludes, and those responses carry no policy.

```ts
// proxy.ts
import { NextResponse } from 'next/server';
import { applyCanvasHeaders } from '@drupal-canvas/headless-next/middleware';

import type { NextRequest } from 'next/server';

export function proxy(request: NextRequest) {
  const response = NextResponse.next();
  response.headers.set('Content-Security-Policy', "default-src 'self'");
  return applyCanvasHeaders(request, response);
}
```

**3. Route files** — mount the handlers, one file per route:

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

**4. Session banner** — a server component gathers the session state
(`getDraftData()`, `getDraftEditorOrigin()`, `isDraftSessionExpired()`) and
renders `<DraftSession>` from `@drupal-canvas/headless-next/client` with a
render prop that owns the banner markup.

**5. Component tree** — pass the structured content returned by `fetchPage()` to
`<CanvasComponentTree>`:

```tsx
import { CanvasComponentTree } from '@drupal-canvas/headless-next/CanvasComponentTree';

<CanvasComponentTree tree={page.content} />;
```

`withCanvas()` generates a registry of every discovered component
implementation, and the renderer consumes it automatically. During development
the registry updates when components are added, removed, or renamed.

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
