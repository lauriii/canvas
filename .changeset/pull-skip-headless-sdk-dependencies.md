---
'@drupal-canvas/cli': patch
---

`canvas pull` no longer adds `@drupal-canvas/headless` or `@drupal-canvas/headless-*` dependencies from the pulled `package.json`. The site may store a `package.json` pushed from a headless codebase, and those dependencies would make the next `canvas push` treat every component as external. Headless SDK dependencies already declared in the local `package.json` are kept.
