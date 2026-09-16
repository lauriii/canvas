# @drupal-canvas/headless

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
