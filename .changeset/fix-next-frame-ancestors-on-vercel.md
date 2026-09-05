---
"@drupal-canvas/headless-next": minor
---

Send the `frame-ancestors` policy from middleware instead of `next.config`.

- Draft previews were refused on Vercel: the cookie condition on the
  `next.config` header rule was matched against the raw, percent-encoded
  `Cookie` header there and against the decoded value by Next.js's own server,
  so the editor origin was never added and the editor's iframe was blocked.
- New `@drupal-canvas/headless-next/middleware` entry point exporting
  `canvasMiddleware` and `applyCanvasHeaders()`. Apps must add a `proxy.ts`
  (`middleware.ts` before Next.js 16) that mounts one of them; the build warns
  with instructions while nothing does.
- `withCanvas()` no longer configures `Content-Security-Policy`, and no longer
  rewrites the app's own `headers()` rules. It fails the build on a `headers()`
  rule that sets one: hosting platforms apply those rules after the middleware
  runs and replace the whole value.
