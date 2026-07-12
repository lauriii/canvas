# Canvas Headless example app

A Next.js app the `canvas_headless` module embeds in the Canvas editor
frame. It demonstrates the module's draft-preview authentication end to
end:

- Draft mode activates by exchanging a Drupal-signed, single-use preview
  assertion (RFC 7523 JWT-bearer grant) for an access token bound to the
  initiating editor. No client secret anywhere.
- The front page lists articles and Canvas pages via JSON:API (draft
  sessions see working copies).
- Every other path resolves through Drupal's routing via the Lupus
  Decoupled CE API (`/ce-api/{path}`) and renders the returned custom
  element data.
- The session renews in place over an origin-checked postMessage protocol
  with the embedding Canvas editor; see `components/draft-session-client.tsx`
  and the Canvas UI's `useHeadlessDraftSession` hook.
- Renewal is PKCE-bound to this server. A relayed assertion reaches the app
  through the page's script context, so Drupal will not redeem one without
  a `code_verifier` this server registered at the previous exchange and
  keeps in the `httpOnly` session cookie (`lib/drupal-draft/pkce.ts`). An
  injected script can intercept the assertion and still get no token.
- Exiting draft mode is a `POST` (`app/api/disable-draft/route.ts`),
  submitted by a form in the banner: it clears the session cookies, and a
  `GET` link to it would be eligible for prefetching.

## Setup

```bash
cp .env.example .env.local  # defaults match the canvas-env DDEV setup
npm install
npm run dev                 # http://localhost:3000
```

The `canvas_headless` module's default `frontend_url`
(`http://localhost:3000`) points at this app's dev server. Embedded draft
mode relies on CHIPS partitioned cookies; on a plain-http localhost origin
that works in Chromium-based browsers (localhost is a trustworthy origin).
