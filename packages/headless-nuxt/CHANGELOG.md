# @drupal-canvas/headless-nuxt

## 0.5.0

### Minor Changes

- 24800cb: Add `fetchEntity()` for rendering a single entity through Canvas,
  including explicit view modes.
  - Add a dedicated `/canvas/content-api/entity` endpoint for entity-scoped
    Canvas renders.
  - Support rendering a specific content-template view mode via the `viewMode`
    query parameter.

- e6a9c66: Add support for page variants.

### Patch Changes

- Updated dependencies [24800cb]
- Updated dependencies [e6a9c66]
- Updated dependencies [78e3ff2]
  - @drupal-canvas/headless@0.6.0

## 0.4.0

### Minor Changes

- 71542ec: Add component preview thumbnail support to the Nuxt adapter.

### Patch Changes

- Updated dependencies [71542ec]
  - @drupal-canvas/headless@0.5.0

## 0.3.0

### Minor Changes

- 761cfbb: Set minimum Node.js requirement: >=22.19.0 <23 || >=24.5.0.

### Patch Changes

- Updated dependencies [761cfbb]
  - @drupal-canvas/headless@0.4.0

## 0.2.1

### Patch Changes

- 9c6de1e: Fix framework bindings to use the isomorphic rendered-page exports.
- Updated dependencies [9c6de1e]
  - @drupal-canvas/headless@0.3.0

## 0.2.0

### Minor Changes

- f16deaf: Support page and redirect results from `/canvas/content-api` in the
  Nuxt binding.

### Patch Changes

- Updated dependencies [f16deaf]
- Updated dependencies [7f3da8f]
  - @drupal-canvas/headless@0.2.0

## 0.1.1

### Patch Changes

- 445ac1a: Require the Headless SDK version containing the latest draft session
  recovery fixes.

## 0.1.0

### Minor Changes

- 4e4c6d0: Add Canvas editor frame editing capabilities for headless frontends.

### Patch Changes

- Updated dependencies [4e4c6d0]
- Updated dependencies [e2e3254]
  - @drupal-canvas/headless@0.1.0
