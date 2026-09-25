# @drupal-canvas/headless

## 0.11.0

### Minor Changes

- aaaa869: Keep preview context specific to each request so tabs do not change
  each other's rendering settings. Authenticated previews can exclude Canvas
  auto-saves with `excludeAutoSave`, and `fetchPage()` accepts explicit preview
  context.

### Patch Changes

- aaaa869: Delegate eligible embedded link clicks in draft mode to the host when
  it advertises navigation support.
- Updated dependencies [73d9fb8]
  - drupal-canvas@0.7.1

## 0.10.0

### Minor Changes

- ed541e3: Make React Code Components portable to headless frontends.
  - `fetchPage()` returns `page.context`, the page and site context React Code
    Components read through the `drupal-canvas` context hooks; sites without the
    context API yield `null` page and site context.
  - `getPublicClient()`, `getDraftClient()`, and `getClient()` create the shared
    `drupal-canvas` client (`createJsonApiClient()`) instead of a separate
    subclass. Draft reads resolve working copies before serialization, and the
    default serialization now matches `drupal-canvas`.
  - `CANVAS_JSONAPI_URL` overrides JSON:API discovery with a full upstream URL;
    `CANVAS_JSONAPI_PROXY_PATH` sets the application's proxy path (default
    `/api/canvas/jsonapi`).
  - `createJsonApiProxyHandler()` and `DraftServer.handleJsonApiProxy()` add a
    same-origin JSON:API proxy that authenticates from the draft session, keeps
    requests within the configured backend's JSON:API and path-translation
    endpoints, validates redirects, refuses cross-site state-changing requests,
    returns a session error for expired sessions, and keeps draft responses out
    of shared caches.
  - `DraftServer.getJsonApiRuntimeConfig()` prepares the nonsecret configuration
    browser clients are created from.

  The proxy surfaces a session token Drupal rejects (401) as the session error,
  passes 304 through, merges Drupal's `Vary` with `Cookie`, and only rewrites
  redirect statuses. `CANVAS_JSONAPI_URL` overrides JSON:API requests only; path
  translation stays on `CANVAS_SITE_URL`.

  `CANVAS_JSONAPI_SITE_URL` gives a JSON:API URL on another site its site base,
  so language-prefixed reads and the proxy resolve under it.

### Patch Changes

- Updated dependencies [ed541e3]
  - drupal-canvas@0.7.0

## 0.9.0

### Minor Changes

- e1fae30: Add `route.negotiatedLanguage` and `route.translations` to the page
  data returned by `fetchPage()`. Translation entries share the fields and
  switcher behavior of `mainEntity.translations` returned by `getPageData()`
  from `drupal-canvas`. Headless entries additionally include `external` and use
  a different URL form: Drupal request URIs without the installation base path,
  or absolute external URLs.

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
