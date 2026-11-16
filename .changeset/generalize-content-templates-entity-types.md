---
"@drupal-canvas/types": patch
---

Add `contentTemplateEntityTypes` to `DrupalSettings` to support content templates for every eligible content entity type, not just nodes.

Narrow `entityTypeLabels` in `DrupalSettings` to a bundle-keyed map: `CanvasController` now always emits a map of bundle labels, so the `string` half of the union is unreachable.
