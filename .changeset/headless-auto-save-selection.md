---
"@drupal-canvas/headless": minor
---

Keep preview context specific to each request so tabs do not change each other's
rendering settings. Authenticated previews can exclude Canvas auto-saves with
`excludeAutoSave`, and `fetchPage()` accepts explicit preview context.
