---
'@drupal-canvas/eslint-config': minor
---

Skip the `component-exports` and `component-imports` rules when the Canvas
Headless SDK is detected in the project's `package.json`. Both rules encode
constraints that only apply when Drupal renders the component, but a headless app
renders its own components and owns its module graph.
