---
"@drupal-canvas/cli": minor
---

Add support for pushing and pulling local modules, assets, and `package.json`.

- `push` uploads local module and asset dependencies used by components, carrying their disk path and, for text modules, their verbatim source.
- `push` stores the project's `package.json` verbatim alongside the global CSS.
- `pull` writes local module and asset dependencies, and `package.json`, back to the project. Existing files are overwritten by default, or skipped with `--skip-overwrite`.