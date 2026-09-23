# @drupal-canvas/headless-angular

Angular 21/22 bindings for the Drupal Canvas Headless SDK. The library uses
Angular 21 partial compilation; the same package supports Angular 21/TypeScript
5.9 and Angular 22/TypeScript 6. The Angular starter defaults to 22.

## Components

```ts
import { Component, input } from '@angular/core';
import { CanvasSlot } from '@drupal-canvas/headless-angular';

@Component({
  selector: 'app-section',
  host: { style: 'display: contents' },
  imports: [CanvasSlot],
  template: `<section [class.wide]="width() === 'wide'">
    <canvas-slot name="content" />
  </section>`,
})
export default class Section {
  readonly width = input('normal');
}
```

Use standalone components with ordinary inputs and a default export in
`index.ts`. Keep `component.yml` machine names and mocks unchanged. Canvas UUID
metadata is not passed as an input. `<canvas-slot />` renders the default slot;
`name` selects a named slot. Render each slot once within its registry
component's view.

Render
`<canvas-component-tree [tree]="page.content" [components]="components" />` with
the generated registry, and import `@drupal-canvas/headless/preview.css` in the
global stylesheet. Draft trees emit editor boundaries and empty drop targets;
published trees do not.

Angular hosts remain in the DOM even with `display: contents`: account for them
in direct-child CSS selectors. Slots use ordinary Angular views and scoped
injectors, without detached DOM or hydration opt-outs.

**Only render trusted Drupal HTML.** Raw tree strings and `CanvasMarkup`'s
`html` input bypass Angular sanitization. Angular's `[innerHTML]` binding
replaces raw HTML descendants during hydration or updates; these descendants do
not retain stateful DOM identity. Interactive components should use Angular
templates.

## Discovery and build

Configure shared discovery options in `canvas.config.json`, including
`componentDir` and `globalCssPath`. Use the installed command before Angular
CLI:

```json
{
  "scripts": {
    "dev": "canvas-angular --watch -- ng serve",
    "check": "canvas-angular -- ngc --noEmit",
    "build": "canvas-angular -- ng build"
  }
}
```

The command generates two modules; ignore both in Git:

- `src/canvas-components.generated.ts`: browser-safe component registry.
- `src/canvas-manifest.generated.ts`: metadata imported **only by server.ts**.

The watcher handles component/config changes, additions, renames and deletions.
Invalid initial metadata fails the command; watch errors recover on valid edits.
Angular CLI handles generated-source updates through its normal reload pipeline.
Production metadata is bundled into the server and needs no component sources or
runtime filesystem discovery.

## Server and routing

Use `createCanvasHandler({ manifest })` from
`@drupal-canvas/headless-angular/server` in the Angular Node SSR server. It
reads `CANVAS_SITE_URL` on the server. Pass the same validated Web Request to
Canvas and `AngularNodeAppEngine.handle(request, context)`, then write the
response with Angular's `writeResponseToNodeResponse`.

Use the Angular starter's server and deployment instructions for request
conversion and exact allowed hosts, especially behind HTTPS termination. Never
trust arbitrary forwarded headers. Keep server code and the generated manifest
out of browser imports. Use `RenderMode.Server`, not prerendering, for Canvas
routes.

Add `provideCanvas()`, `provideClientHydration()` and `provideRouter(routes)` to
both browser and server bootstrap providers. The catch-all route is:

```ts
import { canvasPageResolver } from '@drupal-canvas/headless-angular';

export const routes = [
  {
    path: '**',
    component: CanvasPage,
    resolve: { canvas: canvasPageResolver },
    runGuardsAndResolvers: 'always',
  },
];
```

The page injects `CanvasPageStore` and renders `page().content`, or its
not-found UI when `page()` is null. The handler preserves Drupal redirects and
HTTP 404; the resolver updates title, meta, links and JSON-LD through
`CanvasDocumentHead`. Only head nodes marked `data-canvas-head` are owned by the
adapter.

| Endpoint                                      | Method       | Purpose                             |
| --------------------------------------------- | ------------ | ----------------------------------- |
| `/api/draft`                                  | GET          | Assertion redemption and activation |
| `/api/draft/renew`                            | POST         | Identity-pinned, PKCE-bound renewal |
| `/api/disable-draft`                          | POST         | Same-origin exit and redirect       |
| `/api/canvas/components`                      | GET, OPTIONS | Authenticated metadata and CORS     |
| `/api/canvas/page?path=…`                     | GET          | Page and public session data        |
| `/api/canvas/entity?type=…&id=…&viewMode=…`   | GET          | Entity render                       |
| `/api/canvas/component-preview?componentId=…` | GET          | SSR thumbnail preview               |

Each request has its own cookie/server context. CHIPS cookie attributes and
expiry-based deletions are preserved; CSP merges the signed editor's
`frame-ancestors` without weakening app policies. Page, data and redirect
responses are private/no-store. Deployed cross-site previews require HTTPS.

For custom mounting, `createCanvasRequest(request, options)` exposes `server`,
`session`, `loadPage`, `handle` and `finalize`. Always finalize responses to
apply cookies, CSP and cache policy. Never serialize the server accessor.

## Data and draft sessions

Only `{ page, session }` enters TransferState. Tokens, PKCE verifiers, server
configuration and the SSR entity callback stay server-only. Browser data
requests use fixed same-origin endpoints and cookies, not Drupal bearer tokens.

`CanvasPageStore` provides:

- `page()`, `data()`, `session()` and `error()` signals.
- `path()` for the committed route and `pendingPath()` for unfinished
  navigation.
- `refresh()`, serialized/coalesced through the shared SDK queue. Refresh waits
  for navigation, preserves renewed session state and follows redirects.
- `fetchEntity({ type, id, viewMode })` for request-scoped SSR or same-origin
  browser access.

`CanvasDraftSession` starts the shared session, height and geometry machinery
after hydration and cleans it up with the application. It exposes `embedded()`
(null until hydration), `expired()` and `renewState()`. Initial expiry comes
from the server. The app owns banner markup: show it only for an enabled
session, hide the active banner when embedded, and use the session's signed
`renewUrl` for standalone renewal. Exit uses a normal form:

```html
<form ngNoForm method="POST" action="/api/disable-draft">
  <button type="submit">Exit draft</button>
</form>
```

## Development

Build with `npm run build -w @drupal-canvas/headless-angular`. Pack the built
package with `npm pack ./packages/headless-angular/dist`, not the source folder.
Run `npm test -w @drupal-canvas/headless-angular` for the built-output check. No
Workbench integration is included.
