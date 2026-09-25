# @drupal-canvas/headless-tanstack-start

## 0.8.0

### Minor Changes

- aaaa869: Read preview context from each request and accept an explicit context
  argument in `fetchPage(path, context)`.

### Patch Changes

- aaaa869: Delegate eligible embedded link clicks in draft mode to the host when
  it advertises navigation support.
- Updated dependencies [aaaa869]
- Updated dependencies [73d9fb8]
- Updated dependencies [aaaa869]
  - @drupal-canvas/headless@0.11.0
  - @drupal-canvas/headless-react@0.5.1
  - drupal-canvas@0.7.1

## 0.7.0

### Minor Changes

- ed541e3: Mount the same-origin JSON:API proxy for portable Code Components and
  expose the JSON:API runtime configuration (`getJsonApiRuntimeConfig()`)
  browser clients are created from. The React renderers accept
  `context={page.context}` so `usePageContext()` and `useSiteContext()` work in
  headless components. Server rendering follows one contract in every React
  adapter: prefetch draft data with `getClient()` and supply SWR fallback data;
  the hook's client is the same on both sides of hydration.

### Patch Changes

- Updated dependencies [ed541e3]
- Updated dependencies [ed541e3]
- Updated dependencies [ed541e3]
  - drupal-canvas@0.7.0
  - @drupal-canvas/headless-react@0.5.0
  - @drupal-canvas/headless@0.10.0

## 0.6.2

### Patch Changes

- Updated dependencies [e1fae30]
  - @drupal-canvas/headless@0.9.0
  - @drupal-canvas/headless-react@0.4.3

## 0.6.1

### Patch Changes

- 252aa34: Unify the request-time `frame-ancestors` policy across framework
  adapters. When `CANVAS_EDITOR_ORIGINS` is unset, admit `'self'`, the valid
  `CANVAS_SITE_URL` origin and the draft-session editor origin. An explicit
  whitespace- or comma-separated list replaces both defaults; empty or invalid
  configuration never restores them.

  Normalize and deduplicate sources centrally. Reject credentialed/non-HTTP(S)
  URLs, wildcard or delimiter-bearing hosts, and literal IPv6 addresses. DNS
  hostnames resolving to IPv6 remain supported. Application-owned
  `frame-ancestors` stays authoritative; other CSP directives are preserved. The
  Astro and TanStack Vite integrations also load `CANVAS_EDITOR_ORIGINS` from
  environment files, including explicit empty values.

  This changes embedding policy only, not draft authentication or message trust.

- Updated dependencies [252aa34]
  - @drupal-canvas/headless@0.8.0
  - @drupal-canvas/headless-react@0.4.2

## 0.6.0

### Minor Changes

- 98b764a: Automatically discover the site's JSON:API prefix so sites using a
  non-default prefix (e.g. `/api`) work without configuration.

  `getPublicClient()` and `getDraftClient()` are now async — `await` them like
  `getClient()`.

### Patch Changes

- Updated dependencies [98b764a]
- Updated dependencies [fc2cbd1]
- Updated dependencies [bde9b02]
  - @drupal-canvas/headless@0.7.0
  - @drupal-canvas/headless-react@0.4.1

## 0.5.0

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

## 0.4.0

### Minor Changes

- 71542ec: Add component preview thumbnail support to the TanStack Start
  adapter.

### Patch Changes

- Updated dependencies [71542ec]
  - @drupal-canvas/headless@0.5.0
  - @drupal-canvas/headless-react@0.3.1

## 0.3.0

### Minor Changes

- 761cfbb: Set minimum Node.js requirement: >=22.19.0 <23 || >=24.5.0.

### Patch Changes

- Updated dependencies [761cfbb]
  - @drupal-canvas/headless-react@0.3.0
  - @drupal-canvas/headless@0.4.0

## 0.2.0

### Minor Changes

- 9c6de1e: Add an isomorphic `./head` entry for route head translation.

### Patch Changes

- Updated dependencies [9c6de1e]
- Updated dependencies [9c6de1e]
  - @drupal-canvas/headless@0.3.0
  - @drupal-canvas/headless-react@0.2.1

## 0.1.0

### Minor Changes

- f16deaf: Add a TanStack Start document-head helper for `/canvas/content-api`
  results.

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
