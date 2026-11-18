# @drupal-canvas/json-render

Framework-neutral document and catalog contracts for the json-render documents
returned by Drupal Canvas.

Framework renderers handle document validation. Lower-level utilities for
implementing framework renderers are available from
`@drupal-canvas/json-render/renderer`.

Canvas JavaScript component types preserve their component ID, such as
`js.HeroBanner` or `js.hello_card`. Framework registries can use the original
machine name or the exact wire type. Other json-render producers can keep their
native component types.

## Installation

```bash
npm install @drupal-canvas/json-render
```

## Usage

Renderer implementations can import component lookup, tree traversal, and
preview marker helpers from the dedicated subpath:

```ts
import {
  findCanvasComponent,
  getCanvasComponentTreeElementSlots,
} from '@drupal-canvas/json-render/renderer';
```

`CanvasComponentTreeSchema` validates the document shape, root, element
references, and cycles. The exported TypeScript document types are inferred from
this Zod schema. Canvas catalogs include the preview-only `canvas-marker`
structural type. Its `markerId` prop identifies the marker type.

Use `defineCatalog` to define a Canvas json-render catalog for application
components. It uses the Canvas schema and adds the structural types required by
Canvas renderers:

```ts
import { z } from 'zod';
import { defineCatalog } from '@drupal-canvas/json-render';

const catalog = defineCatalog({
  'js.hero': {
    props: z.object({ heading: z.string() }),
    slots: ['default'],
  },
});
```

## Preview styles

The `preview.css` export styles empty Canvas slot and region drop areas and the
page content marker placeholder:

```ts
import '@drupal-canvas/json-render/preview.css';
```

Slot emptiness checks preserve Canvas component markers, including markers in
server-rendered markup. Comments without component markers remain default slot
content.
