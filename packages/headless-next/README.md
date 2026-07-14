# @drupal-canvas/headless-next

Next.js adapter for the Drupal Canvas Headless SDK (`@drupal-canvas/headless`):
draft-mode preview bound to the editing user, in-place session renewal inside
the Canvas editor frame, and the component metadata endpoint Drupal Canvas
registers the app's components from.

## Setup

Three pieces, all wiring:

**1. next.config.ts** — the config wrapper generates the component manifest at
build time, transpiles the raw-TypeScript SDK packages, and sends the CSP
`frame-ancestors` header from `DRAFT_ALLOWED_FRAME_ANCESTORS`:

```ts
import { withCanvas } from '@drupal-canvas/headless-next/config';

export default withCanvas();
```

(`./config` is a separate entry on purpose: next.config runs outside any request
scope, so it must not load this package's server entry, which reaches
`next/headers`.)

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

**3. The session banner** — a server component gathers the session state
(`getDraftData()`, `getDraftConfig()`, `isDraftSessionExpired()`) and renders
`<DraftSession>` from `@drupal-canvas/headless-next/client` with a render prop
that owns the banner markup. The component runs the renewal protocol either way;
the render prop is optional.

Environment: `DRUPAL_BASE_URL` (required) and `DRAFT_ALLOWED_FRAME_ANCESTORS`
(the embedder origin allowlist).

Data access from app code: `getClient()` (draft-aware JSON:API client),
`fetchPage()` (rendered content, resolved through Drupal's routing), both
draft-session-aware.

## The component metadata endpoint

`GET` answers the codebase's component registry (every `component.yml` under the
`canvas.config.json` `componentDir`) in a versioned envelope; see
`@drupal-canvas/headless/components-endpoint` for the payload shape. Callers
authenticate by presenting a fresh, single-use Drupal preview assertion as a
Bearer token, verified by redeeming it at Drupal's own token endpoint
(proof-by-redemption — the app holds no key material). Browser callers are
CORS-gated to the embedder origin allowlist.

In production the endpoint serves the manifest `withCanvas()` wrote at
`next build` (component sources are typically absent at runtime, and the
registry should describe the deployed build); in development it scans live, so a
new component is visible on the next fetch.
