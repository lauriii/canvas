# @drupal-canvas/headless-angular

## 0.2.2

### Patch Changes

- Updated dependencies [ed541e3]
  - @drupal-canvas/headless@0.10.0

## 0.2.1

### Patch Changes

- Updated dependencies [e1fae30]
  - @drupal-canvas/headless@0.9.0

## 0.2.0

### Minor Changes

- 252aa34: Make the shared Headless SDK a regular dependency, matching the other
  adapters. Release Angular's shared editor-origin policy support as a minor
  update rather than allowing the SDK's minor release to trigger a
  peer-dependency major bump.

### Patch Changes

- Updated dependencies [252aa34]
  - @drupal-canvas/headless@0.8.0

## 0.1.0

### Minor Changes

- 732b7e5: Add Angular 21 and 22 bindings for headless Canvas rendering,
  server-side draft sessions, and component discovery.
  - Render component trees with scoped slots and hydration-safe component
    identity.
  - Integrate draft previews, content navigation, and server endpoints with
    Angular SSR.
  - Generate component registries and metadata with the `canvas-angular` build
    and watch command.
