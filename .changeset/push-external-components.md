---
"@drupal-canvas/cli": minor
---

Push components as external metadata when the Canvas Headless SDK is present.

- `push` detects `@drupal-canvas/headless` in the project and uploads every component as `type: external` — metadata only, no JS/CSS — since a decoupled app owns rendering.
- A `type: external` in a component's `component.yml` is honored the same way without the SDK. The YAML file is never mutated; `type` is set on the API payload only.
- Entry-less components (for example `.vue`, `.astro`, `.svelte` single-file components) are discovered instead of being dropped as missing a JS entry.
- Existing external components are updated rather than recreated, and are never deleted by `push`. A local/remote component type mismatch is rejected at planning time with an actionable error instead of a confusing server rejection.
