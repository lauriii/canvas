# @drupal-canvas/json-render-vue

Vue renderer for json-render documents returned by Drupal Canvas.

## Installation

```bash
npm install @drupal-canvas/json-render-vue vue
```

## Usage

```ts
import { h } from 'vue';
import CanvasComponentTree from '@drupal-canvas/json-render-vue';

h(CanvasComponentTree, {
  tree: page.content,
  components: { 'hello-card': HelloCard },
  preview: page.isPreview,
});
```

The renderer passes Canvas slots as Vue slots. It supports server rendering and
emits Canvas boundary markers only when `preview` is true.

Registry entries may use a Headless component machine name or an exact
json-render element type such as `js.article-card`.
