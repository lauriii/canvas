# @drupal-canvas/headless-host

Host-side implementation of the Drupal Canvas headless draft-preview protocol.
Use it in any application that embeds a Canvas headless frontend app in an
iframe and runs inside an authenticated Drupal session — the Canvas editor uses
it for its own editor frame.

The package owns the protocol state machine:

- activation: mint a single-use preview assertion and load the app's draft-mode
  endpoint in the iframe,
- the renewal lane: relay fresh assertions to the app over origin-checked
  postMessage, so sessions renew in place without a reload. Renewal mints carry
  a `renewal` flag: Drupal only redeems the resulting assertions with PKCE proof
  held by the app's server, so a script inside the iframe cannot exchange an
  intercepted assertion for a token,
- the recovery lane: reset the iframe to a new activation URL when the app
  reports an expired session, one attempt per expiry.

Transport specifics stay with the consumer: `fetchAssertion` is a callback, so
each host decides how it reaches its assertion-minting endpoint (for the Canvas
editor: `POST /canvas-headless/assertion` with an `X-CSRF-Token` header from
core's `/session/token`).

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
// Later: host.destroy();
```

The app side of the protocol ships with the example app in the `canvas_headless`
module (`lib/drupal-draft` and `components/draft-session-client.tsx`).
