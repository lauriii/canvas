---
'@drupal-canvas/cli': minor
---

Modules and themes can declare the npm package their code component JavaScript is published as, under `canvas.npm` in their info file. `canvas pull` adds the declared packages to `package.json` without overwriting versions you set, and records what it wrote under `canvas.npmDependencies`. `canvas build` and `canvas push` fail before bundling when a declared package is not installed, and warn when it is installed at a different version than the site's extension declares.
