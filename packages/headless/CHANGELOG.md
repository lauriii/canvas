# @drupal-canvas/headless

## 0.8.0

### Minor Changes

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

## 0.7.0

### Minor Changes

- 98b764a: Resolve the JSON:API prefix from the site's now-public
  `/canvas/api/v0/site-data` endpoint (previously editor-only) instead of always
  using the `/jsonapi` default, so sites serving JSON:API from a non-default
  prefix (e.g. `/api`) work without configuration. When that endpoint is
  unreachable, the `CANVAS_JSONAPI_PREFIX` environment variable (or a config
  override) applies as a fallback.

  `getPublicClient()` and `getDraftClient()` are now async — `await` them like
  `getClient()`.

### Patch Changes

- fc2cbd1: Preserve Canvas's selected read-only preview language in draft
  sessions and forward it through `fetchPage()` only while the session is live.

## 0.6.0

### Minor Changes

- 24800cb: Add `fetchEntity()` for rendering a single entity through Canvas,
  including explicit view modes.
  - Add a dedicated `/canvas/content-api/entity` endpoint for entity-scoped
    Canvas renders.
  - Support rendering a specific content-template view mode via the `viewMode`
    query parameter.

- e6a9c66: Add page variant support to headless draft previews.

### Patch Changes

- 78e3ff2: Validate authored Code Component metadata against the shared Canvas
  JSON Schema when building component registries and metadata payloads.

## 0.5.0

### Minor Changes

- 71542ec: Add component preview support to the core Headless SDK.

## 0.4.0

### Minor Changes

- 761cfbb: Set minimum Node.js requirement: >=22.19.0 <23 || >=24.5.0.

## 0.3.0

### Minor Changes

- 9c6de1e: Expose rendered-page types, redirect detection, and JSON script
  serialization from the isomorphic root entry.

## 0.2.0

### Minor Changes

- f16deaf: Use `/canvas/content-api` in the draft-aware page client.
- 7f3da8f: Add content-template view mode support to live draft content
  requests.

## 0.1.1

### Patch Changes

- ea4b308: Fix draft session recovery when background tabs delay token renewal.

## 0.1.0

### Minor Changes

- 4e4c6d0: Add Canvas editor frame editing capabilities for headless frontends.
- e2e3254: Set the Canvas editor frame height for the embedded headless
  application.
