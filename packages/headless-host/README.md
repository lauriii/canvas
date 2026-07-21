# @drupal-canvas/headless-host

Host-side implementation of the Drupal Canvas Headless draft-preview protocol,
for applications that embed a Canvas headless frontend app.

Use it in any application that embeds a Canvas headless frontend in an iframe
and can mint Drupal preview assertions for the editing user — typically one
running inside an authenticated Drupal session. The Drupal Canvas UI uses it for
the editor's preview frame. The package owns the protocol state machine:
activation, in-place session renewal over origin-checked postMessage, recovery
after an expired session, and content refresh after Canvas auto-saves.

## Installation

```bash
npm install @drupal-canvas/headless-host
```

## Usage

```ts
import { createHeadlessPreviewHost } from '@drupal-canvas/headless-host';

const host = createHeadlessPreviewHost({
  iframe: document.querySelector('iframe#preview'),
  frontendOrigin: 'http://localhost:3000',
  draftUrl: 'http://localhost:3000/api/draft',
  fetchAssertion: async (params) => {
    const url = new URL('/canvas-headless/assertion', window.location.origin);
    Object.entries(params).forEach(([name, value]) =>
      url.searchParams.set(name, value),
    );
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'X-CSRF-Token': await getCsrfToken() },
    });
    return (await response.json()).assertion;
  },
  onEvent: (event) => console.log(event),
});

await host.activate({ entity_type: 'canvas_page', entity: '5' });
// After Canvas reports that an auto-save request succeeded:
host.refresh();
// Later: host.destroy();
```

`fetchAssertion` is a callback, so each host decides how it reaches its
assertion-minting endpoint. The Canvas editor posts to
`/canvas-headless/assertion` with an `X-CSRF-Token` header from core's
`/session/token`.

## Learn more

The app side of the protocol ships in
[`@drupal-canvas/headless`](https://www.npmjs.com/package/@drupal-canvas/headless)
and its framework adapters; the message-type constants this package re-exports
are declared there.
