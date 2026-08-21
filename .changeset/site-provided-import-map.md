---
'@drupal-canvas/cli': minor
'@drupal-canvas/workbench': minor
---

Support components that import entries a Drupal module added to the Canvas import map.

- `push` and `build` no longer fail on a bare specifier the project cannot resolve. It is left unbundled so the browser resolves it against the site's import map, the way the in-browser code editor already does.
- `pull` records the site's import map as `canvas-importmap.json`. Commit it, and `build` and `push` fail on any such specifier the site does not resolve, offline and in CI. Without it they report the specifiers instead.
- Workbench previews components that use these imports, fetching the module from the site so its own imports resolve to the same copies the component uses. Requires `CANVAS_SITE_URL`.
