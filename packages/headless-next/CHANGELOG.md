# @drupal-canvas/headless-next

## 0.6.1

### Patch Changes

- Updated dependencies [e1fae30]
  - @drupal-canvas/headless@0.9.0
  - @drupal-canvas/headless-react@0.4.3

## 0.6.0

### Minor Changes

- 252aa34: Resolve `frame-ancestors` at request time through an
  application-mounted middleware (Next.js 15) or proxy (Next.js 16), rather than
  static header rules whose cookie matching differs between Next.js and hosted
  routing layers.
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
    `CANVAS_EDITOR_ORIGINS` admits `'self'`, the `CANVAS_SITE_URL` origin and
    the draft-session editor origin. An explicit list replaces both defaults,
    even when empty or invalid. Literal IPv6 addresses are rejected; use a DNS
    hostname (hostnames resolving to IPv6 remain supported).

### Patch Changes

- 252aa34: Keep Next.js subpath specifiers unresolved in the built output, so
  consumer Turbopack builds succeed.
- Updated dependencies [252aa34]
  - @drupal-canvas/headless@0.8.0
  - @drupal-canvas/headless-react@0.4.2

## 0.5.0

### Minor Changes

- 98b764a: Automatically discover the site's JSON:API prefix so sites using a
  non-default prefix (e.g. `/api`) work without configuration.

  `getPublicClient()` and `getDraftClient()` are now async — `await` them like
  `getClient()`.

### Patch Changes

- bde9b02: Ship a compiled `dist` build instead of raw TypeScript source,
  matching `@drupal-canvas/headless`.
- Updated dependencies [98b764a]
- Updated dependencies [fc2cbd1]
- Updated dependencies [bde9b02]
  - @drupal-canvas/headless@0.7.0
  - @drupal-canvas/headless-react@0.4.1

## 0.4.0

### Minor Changes

- 24800cb: Add `fetchEntity()` for rendering a single entity through Canvas,
  including explicit view modes.
  - Add a dedicated `/canvas/content-api/entity` endpoint for entity-scoped
    Canvas renders.
  - Support rendering a specific content-template view mode via the `viewMode`
    query parameter.

### Patch Changes

- Updated dependencies [24800cb]
- Updated dependencies [e6a9c66]
- Updated dependencies [e6a9c66]
- Updated dependencies [78e3ff2]
  - @drupal-canvas/headless@0.6.0
  - @drupal-canvas/headless-react@0.4.0

## 0.3.0

### Minor Changes

- 71542ec: Add component preview thumbnail support to the Next.js adapter.

### Patch Changes

- Updated dependencies [71542ec]
  - @drupal-canvas/headless@0.5.0
  - @drupal-canvas/headless-react@0.3.1

## 0.2.0

### Minor Changes

- 761cfbb: Set minimum Node.js requirement: >=22.19.0 <23 || >=24.5.0.

### Patch Changes

- Updated dependencies [761cfbb]
  - @drupal-canvas/headless-react@0.3.0
  - @drupal-canvas/headless@0.4.0

## 0.1.1

### Patch Changes

- 9c6de1e: Fix framework bindings to use the isomorphic rendered-page exports.
- Updated dependencies [9c6de1e]
- Updated dependencies [9c6de1e]
  - @drupal-canvas/headless@0.3.0
  - @drupal-canvas/headless-react@0.2.1

## 0.1.0

### Minor Changes

- f16deaf: Add a Next.js document-head helper for `/canvas/content-api` results.

### Patch Changes

- Updated dependencies [f16deaf]
- Updated dependencies [f16deaf]
- Updated dependencies [7f3da8f]
  - @drupal-canvas/headless@0.2.0
  - @drupal-canvas/headless-react@0.2.0

## 0.0.3

### Patch Changes

- 445ac1a: Require the Headless SDK version containing the latest draft session
  recovery fixes.
- Updated dependencies [445ac1a]
  - @drupal-canvas/headless-react@0.1.1

## 0.0.2

### Patch Changes

- Updated dependencies [4e4c6d0]
- Updated dependencies [e2e3254]
  - @drupal-canvas/headless@0.1.0
  - @drupal-canvas/headless-react@0.1.0
