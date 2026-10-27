---
"@drupal-canvas/cli": patch
---

Add the `v0.langcode` entry to `dataDependencies.drupalSettings` for components that import `useDateFormatter` or `useSiteContext` from `drupal-canvas/react`, so that published pages format dates in the active Drupal locale.
