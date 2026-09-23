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
build time. It no longer sends a static CSP header; mount the request-time
helper in step 2 as well:

```ts
import { withCanvas } from '@drupal-canvas/headless-next/config';

export default withCanvas();
```

**2. Request-time CSP (required)** — create `proxy.ts` for Next.js 16, at the
same level as `app` or `pages` (inside `src` when applicable):

```ts
export { canvasMiddleware as default } from '@drupal-canvas/headless-next/middleware';
```

For **Next.js 15**, put the same export in **`middleware.ts`**. Use an
exports-aware TypeScript `moduleResolution` such as `bundler`, and follow
`pageExtensions` if customized. Without a matcher this runs on every request;
any matcher you add must cover all document/preview routes. Verify deployed
headers: omitting this setup means Canvas supplies no framing policy.

If the app already has middleware/proxy or a CSP, compose on the same response:

```ts
// proxy.ts (Next.js 16); name the file middleware.ts for Next.js 15.
import { NextResponse } from 'next/server';
import { applyCanvasHeaders } from '@drupal-canvas/headless-next/middleware';

import type { NextRequest } from 'next/server';

export default function handler(request: NextRequest) {
  const response = NextResponse.next();
  // Supply your COMPLETE existing CSP here, including any path-specific rules.
  response.headers.set('Content-Security-Policy', "default-src 'self'");
  return applyCanvasHeaders(request, response);
}
```

**Migration:** move your complete CSP from `next.config.headers()` onto the
response passed to `applyCanvasHeaders`. `withCanvas()` rejects static CSP rules
because header layers can replace, rather than merge, policies. Other static
headers and report-only CSP are unchanged. The helper preserves other directives
and leaves application-owned `frame-ancestors` authoritative. Do not overwrite
the result in a later handler. Reconcile hosting/CDN CSP separately: the helper
cannot see it, and multiple policies intersect.

By default, `frame-ancestors` admits `'self'`, the `CANVAS_SITE_URL` origin and
the draft-session editor origin. Set `CANVAS_EDITOR_ORIGINS` to a comma- or
whitespace-separated list of HTTP(S) URLs to replace both defaults. An empty or
entirely invalid list admits only `'self'`. Origins are normalized and
deduplicated; credentials, wildcards and literal IPv6 are rejected. For IPv6,
use a DNS hostname. This controls embedding, not draft authorization.

Both variables are read from server `process.env` per request. Restart
self-hosted `next start` with updated environment values; rebuilding is not
needed unless values are embedded, for example through `next.config.env`. For
hosts that embed environment settings, rebuild/redeploy and verify headers
rather than assuming a settings change updates the running deployment.

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

The client's JSON:API prefix is resolved from the site's public site-data
endpoint (fetched once per server instance), so sites serving JSON:API from a
non-default prefix (e.g. `/api`) work without configuration. When that endpoint
is unreachable, the `CANVAS_JSONAPI_PREFIX` environment variable applies, then
the `/jsonapi` default. `getPublicClient()` and `getDraftClient()` are async for
the same reason: `await` them like `getClient()`. For full manual control, use
`JsonApiClient` from `@drupal-api-client/json-api-client` directly.

`fetchEntity({ type, id, viewMode })` renders one content entity without
page-level route or head data. Use it for embedded renders such as teaser cards.

`toNextMetadata()` maps the Canvas head entries that Next.js Metadata can
represent. It omits entries that Next.js Metadata cannot represent. Render
omitted entries as native head elements in the page or layout.
