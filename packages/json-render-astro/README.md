# @drupal-canvas/json-render-astro

Astro renderer for json-render documents returned by Drupal Canvas.

## Installation

```bash
npm install @drupal-canvas/json-render-astro astro
```

## Usage

```astro
---
import CanvasComponentTree from '@drupal-canvas/json-render-astro/CanvasComponentTree.astro';
---

<CanvasComponentTree
  tree={page.content}
  components={{ 'hello-card': HelloCard }}
  preview={page.isPreview}
/>
```

The renderer supports default and named Astro slots, server rendering, and
Canvas boundary markers when `preview` is true.

Registry entries may use a Headless component machine name or an exact
json-render element type such as `js.article-card`.
