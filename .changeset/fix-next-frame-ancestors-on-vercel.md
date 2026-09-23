---
"@drupal-canvas/headless-next": minor
---

Resolve `frame-ancestors` at request time through an application-mounted
middleware (Next.js 15) or proxy (Next.js 16), rather than static header rules
whose cookie matching differs between Next.js and hosted routing layers.

- Mount `canvasMiddleware` from `@drupal-canvas/headless-next/middleware` on
  all document/preview routes, or compose with
  `applyCanvasHeaders(request, response)`. Percent-encoded draft cookies are
  read through Next's parsed cookie API.
- `withCanvas()` no longer emits a static CSP. Move the application's complete
  CSP from `next.config.headers()` to the response passed to the helper;
  static CSP rules now raise an actionable migration error. Existing
  `frame-ancestors` and all other policies on that response are preserved.
  Reconcile separately configured hosting/CDN CSP as well.
- All adapters share the same editor-origin policy: unset
  `CANVAS_EDITOR_ORIGINS` admits `'self'`, the `CANVAS_SITE_URL` origin and the
  draft-session editor origin. An explicit list replaces both defaults, even
  when empty or invalid. Literal IPv6 addresses are rejected; use a DNS hostname
  (hostnames resolving to IPv6 remain supported).
