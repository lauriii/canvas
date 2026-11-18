# @drupal-canvas/json-render-angular

Angular 21/22 renderer for Canvas json-render documents.

## Installation

```bash
npm install @drupal-canvas/json-render-angular
```

## Usage

### Rendering a document

Add `CanvasComponentTree` to the standalone component's `imports` where you
render the document:

```ts
import { CanvasComponentTree } from '@drupal-canvas/json-render-angular';
```

Render a document with your component registry:

```html
<canvas-component-tree
  [tree]="tree"
  [components]="components"
  [preview]="preview"
/>
```

Registry keys can be machine names such as `HeroBanner` or exact types such as
`js.HeroBanner`. The Headless Angular adapter re-exports `CanvasComponentTree`
and `CanvasSlot`. Use `page.content` and `page.isPreview` for the renderer's
tree and preview inputs when using that adapter.

Only render trusted HTML. Angular hosts remain in the DOM with
`display: contents`, which affects direct-child CSS selectors.

### Authoring components with slots

Registered components that render slots need `CanvasSlot`. Import it and add it
to that component's `imports`:

```ts
import { CanvasSlot } from '@drupal-canvas/json-render-angular';
```

Use `<canvas-slot />` for the default slot and `name` for a named slot. Render
each slot once within the component's template:

```html
<canvas-slot /> <canvas-slot name="sidebar" />
```

Rendering a document with `CanvasComponentTree` alone does not require
`CanvasSlot`.

### Editor preview

Enable `preview` for editor markers and empty drop targets. Import their styles
in your global stylesheet:

```css
@import '@drupal-canvas/json-render/preview.css';
```
