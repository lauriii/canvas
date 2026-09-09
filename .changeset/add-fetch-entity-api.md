---
"@drupal-canvas/headless": minor
"@drupal-canvas/headless-astro": minor
"@drupal-canvas/headless-next": minor
"@drupal-canvas/headless-nuxt": minor
"@drupal-canvas/headless-tanstack-start": minor
---

Add `fetchEntity()` for rendering a single entity through Canvas, including
explicit view modes.

- Add a dedicated `/canvas/content-api/entity` endpoint for entity-scoped
  Canvas renders.
- Support rendering a specific content-template view mode via the `viewMode`
  query parameter.
