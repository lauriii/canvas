# @drupal-canvas/cli

## 0.23.1

### Patch Changes

- Updated dependencies [a51630b]
  - @drupal-canvas/eslint-config@0.9.0

## 0.23.0

### Minor Changes

- 761cfbb: Trust system CA certificates to support working with DDEV
  environments over HTTPS.
- 761cfbb: Set minimum Node.js requirement: >=22.19.0 <23 || >=24.5.0.

### Patch Changes

- Updated dependencies [761cfbb]
  - drupal-canvas@0.5.0
  - @drupal-canvas/eslint-config@0.8.0

## 0.22.0

### Minor Changes

- 9bcfe16: Push components as external metadata when the Canvas Headless SDK is
  present.
  - `push` detects `@drupal-canvas/headless` in the project and uploads every
    component as `type: external` — metadata only, no JS/CSS — since a decoupled
    app owns rendering.
  - A `type: external` in a component's `component.yml` is honored the same way
    without the SDK. The YAML file is never mutated; `type` is set on the API
    payload only.
  - Entry-less components (for example `.vue`, `.astro`, `.svelte` single-file
    components) are discovered instead of being dropped as missing a JS entry.
  - Existing external components are updated rather than recreated, and are
    never deleted by `push`. A local/remote component type mismatch is rejected
    at planning time with an actionable error instead of a confusing server
    rejection.

- 8933c5e: Add support for pushing and pulling local modules, assets, and
  `package.json`.
  - `push` uploads local module and asset dependencies used by components,
    carrying their disk path and, for text modules, their verbatim source.
  - `push` stores the project's `package.json` verbatim alongside the global
    CSS.
  - `pull` writes local module and asset dependencies, and `package.json`, back
    to the project. Existing files are overwritten by default, or skipped with
    `--skip-overwrite`.

### Patch Changes

- 7b03749: Fix component pulls to use JSX or TSX entry extensions that match the
  pulled source.

## 0.21.3

### Patch Changes

- 7bcf0cc: Fix reconcile-media to support bearer token authentication

  The reconcile-media command now uses ensureAuthConfig() instead of directly
  requiring clientId/clientSecret, allowing it to work with CANVAS_ACCESS_TOKEN
  bearer token authentication like other commands (push, pull, build).

## 0.21.2

### Patch Changes

- cbb1a53: Fix content template creation by omitting the unsupported `label`
  property from create requests.

## 0.21.1

### Patch Changes

- d71fdd4: Preserve resolved media and link props when pulling and pushing
  global regions.
