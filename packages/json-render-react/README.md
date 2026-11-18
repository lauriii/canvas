# @drupal-canvas/json-render-react

React renderer for json-render documents returned by Drupal Canvas.

## Installation

```bash
npm install @drupal-canvas/json-render-react react
```

## Usage

```tsx
import { CanvasComponentTree } from '@drupal-canvas/json-render-react';

<CanvasComponentTree
  tree={page.content}
  components={{ 'hello-card': HelloCard }}
  preview={page.isPreview}
/>;
```

Named Canvas slots become React props containing rendered nodes. The `default`
slot becomes `children`. The renderer supports server rendering and emits Canvas
boundary markers only when `preview` is true.

Registry entries may use a Headless component machine name or an exact
json-render element type such as `js.article-card`.
