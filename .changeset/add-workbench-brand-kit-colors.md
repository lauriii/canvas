---
"@drupal-canvas/workbench": minor
---

Render brand kit colors from the local `canvas.brand-kit.json` in previews.

- Serve a generated `:root` custom property stylesheet from the file's `colors` array and load it into the preview iframe before the host global CSS.
- Watch the file so editing a color updates an open preview without a site connection.
