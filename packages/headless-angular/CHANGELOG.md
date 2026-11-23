# @drupal-canvas/headless-angular

## 0.3.1

### Patch Changes

- @drupal-canvas/headless@0.13.1

## 0.3.0

### Minor Changes

- c4e025b: Add support for json-render format in the Headless SDK.

  When upgrading, pass the `isPreview` value returned by `fetchPage()` or
  `fetchEntity()` to `CanvasComponentTree`'s `preview` prop. Preview mode is no
  longer inferred from the content tree. Use `preview={page.isPreview}` in React
  and Astro, `:preview="page.isPreview"` in Nuxt, or
  `[preview]="page.isPreview"` in Angular.

### Patch Changes

- Updated dependencies [c4e025b]
- Updated dependencies [c4e025b]
- Updated dependencies [c4e025b]
  - @drupal-canvas/json-render-angular@0.2.0
  - @drupal-canvas/headless@0.13.0

## 0.2.4

### Patch Changes

- Updated dependencies [6573ef1]
- Updated dependencies [0c03680]
- Updated dependencies [0c03680]
- Updated dependencies [e8b34a9]
  - @drupal-canvas/headless@0.12.0

## 0.2.3

### Patch Changes

- aaaa869: Read preview context from each request so tabs do not change each
  other's rendering settings.
- aaaa869: Delegate eligible embedded link clicks in draft mode to the host when
  it advertises navigation support.
- Updated dependencies [aaaa869]
- Updated dependencies [aaaa869]
  - @drupal-canvas/headless@0.11.0

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
