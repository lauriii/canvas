---
'@drupal-canvas/workbench': minor
---

Support the `drupal-canvas` context hooks in both preview paths: the
interactive preview and generated preview entries render inside
`CanvasContextProvider` and `JsonApiClientProvider`, with Workbench page
defaults and the site data the Vite integration loaded once. Legacy
`drupalSettings` consumers keep working, and generated previews declare the
Workbench runtime for the legacy getters and `new JsonApiClient()`.

Generated previews resolve one site-data snapshot for the legacy
`drupalSettings` and the hooks: discovered site data, then the static
settings, then the preview origin.

The generated entry resolves that snapshot from the same inputs as the
bootstrap script instead of reading the legacy settings back.
Editing a component mock refreshes the mounted preview iframe in place
(component state kept, props and mock data updated) instead of remounting it.
