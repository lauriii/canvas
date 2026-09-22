---
"@drupal-canvas/workbench": minor
---

Render brand kit colors from the local `canvas.brand-kit.json` in previews.

- Serve a generated `:root` custom property stylesheet from the file's `colors` map and load it (in guaranteed cascade order) into the preview iframe before the host global CSS.
- Watch the file so editing a color updates an open preview without a site connection.
- Ship `brand-kit.schema.json` beside the other authored-format schemas, covering the whole brand kit file (fonts and colors) for editor tooling and `canvas validate`.
