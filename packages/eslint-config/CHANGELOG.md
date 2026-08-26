# @drupal-canvas/eslint-config

## 0.9.0

### Minor Changes

- a51630b: Skip the `component-exports` and `component-imports` rules when the
  Canvas Headless SDK is detected in the project's `package.json`. Both rules
  encode constraints that only apply when Drupal renders the component, but a
  headless app renders its own components and owns its module graph.

## 0.8.0

### Minor Changes

- 761cfbb: Set minimum Node.js requirement: >=22.19.0 <23 || >=24.5.0.
