---
"@drupal-canvas/cli": patch
---

Add the `v0.langcode` entry to `dataDependencies.drupalSettings` for components that import `canvasFormatDate`, `canvasFormatDateTime`, `canvasFormatTime`, or `canvasFormatDateRange` from `drupal-canvas`, so that published pages format dates in the active Drupal locale.
