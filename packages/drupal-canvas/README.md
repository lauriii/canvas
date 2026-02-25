# Drupal Canvas Code Components Utils

Utilities and base components for building Drupal Canvas Code Components.

## Utilities

### `cn`

Helper for combining Tailwind CSS classes using
[`clsx`](https://www.npmjs.com/package/clsx) and
[`tailwind-merge`](https://www.npmjs.com/package/tailwind-merge). Implementation
[borrowed from shadcn/ui](https://ui.shadcn.com/docs/installation/manual#add-a-cn-helper).

```jsx
import { cn } from 'drupal-canvas';

export default function Example() {
  return <ControlDots className="absolute top-4 left-4 stroke-white" />;
}

const ControlDots = ({ className }) => (
  <svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 31 9"
    fill="none"
    strokeWidth="2"
    className={cn('w-12', className)}
  >
    <ellipse cx="4.13" cy="4.97" rx="3.13" ry="2.97" />
    <ellipse cx="15.16" cy="4.97" rx="3.13" ry="2.97" />
    <ellipse cx="26.19" cy="4.97" rx="3.13" ry="2.97" />
  </svg>
);
```

### `getPageData`

Access information about the current page.

```js
import { getPageData } from 'drupal-canvas';

const { pageTitle, breadcrumbs, mainEntity } = getPageData();
const { bundle, entityTypeId, uuid } = mainEntity;
```

#### Main entity metadata

The main entity is the primary Drupal entity (e.g. article, canvas_page, blog)
associated with the current page. Access main entity metadata of the page you
are on with `getPageData`. This can be used to construct JSON:API parameters for
requests.
[View documentation and example here.](https://project.pages.drupalcode.org/canvas/code-components/data-fetching#main-entity-metadata)

### `getSiteData`

Access information about the site.

```js
import { getSiteData } from 'drupal-canvas';

const { baseUrl, branding } = getSiteData();
const { homeUrl, siteName, siteSlogan } = branding;
```

### `sortLinksetMenu`

Sort a menu linkset returned by
[Drupal core's linkset endpoint](https://www.drupal.org/docs/develop/decoupled-drupal/decoupled-menus/decoupled-menus-overview):

```jsx
import { sortLinksetMenu } from 'drupal-canvas';

const { data } = useSWR('/system/menu/main/linkset', async (url) => {
  const response = await fetch(url);
  return response.json();
});
const menu = sortLinksetMenu(data);
```

### `getNodePath`

Given a node returned from `JSON:API`, return either the path alias or fall back
to the `/node/[nid]` path.

```jsx
import { getNodePath } from 'drupal-canvas';

const articles = data.map((article) => ({
  ...article,
  _path: getNodePath(article),
}));
```

### `sortMenu`

Sort menu items from the
[JSON:API Menu Items](https://www.drupal.org/project/jsonapi_menu_items) module
into a tree with additional `_children` and `_hasSubmenu` properties.

```jsx
import { JsonApiClient, sortMenu } from 'drupal-canvas';

const client = new JsonApiClient();
const { data } = useSWR(['menu_items', 'main'], ([type, resourceId]) =>
  client.getResource(type, resourceId),
);
const menu = sortMenu(data);
```

### `JsonApiClient`

[JSON:API client](https://www.npmjs.com/package/@drupal-api-client/json-api-client)
automatically configured with a `baseUrl` as well as
[Jsona](https://www.npmjs.com/package/jsona) for
[deserialization](https://project.pages.drupalcode.org/api_client/jsonapi-tutorial/deserializing-data/).

[Drupal core's JSON:API module](https://www.drupal.org/docs/core-modules-and-themes/core-modules/jsonapi-module)
must be enabled to use this client.

```jsx
import { JsonApiClient } from 'drupal-canvas';
import { DrupalJsonApiParams } from 'drupal-jsonapi-params';
import useSWR from 'swr';

const client = new JsonApiClient();

export default function List() {
  const { data, error, isLoading } = useSWR(
    [
      'node--article',
      {
        queryString: new DrupalJsonApiParams()
          .addInclude(['field_tags'])
          .getQueryString(),
      },
    ],
    ([type, options]) => client.getCollection(type, options),
  );

  if (error) return 'An error has occurred.';
  if (isLoading) return 'Loading...';
  return (
    <ul>
      {data.map((article) => (
        <li key={article.id}>{article.title}</li>
      ))}
    </ul>
  );
}
```

You can override the `baseUrl` and any default options:

```js
const client = new JsonApiClient('https://drupal-api-demo.party', {
  serializer: undefined,
  cache: undefined,
});
```

If working outside of Drupal Canvas, you can use the
[`@drupal-canvas/vite-plugin`](https://www.npmjs.com/package/@drupal-api-client/json-api-client)
to automatically configure the base URL for you. Otherwise you must explicitly
provide a base URL.

### json-render Utils

Utilities for working with [json-render](https://json-render.dev) specs and
Drupal Canvas component trees.

> **Note:** These utilities currently depend on named slots support proposed for
> json-render in <https://github.com/vercel-labs/json-render/pull/105>.

#### `canvasTreeToSpec`

Converts a flat Drupal Canvas component tree to a
[json-render spec](https://json-render.dev/docs/specs). Canvas stores components
as a flat array linked by `parent_uuid`; json-render uses a spec object with a
single root element and a flat map of elements linked by `children` and `slots`.
This function builds the spec and, when there are multiple root components,
wraps them in a synthetic `canvas:component-tree` element. Throws an error if
the tree contains no root component.

```js
import { canvasTreeToSpec } from 'drupal-canvas/json-render-utils';

const components = [
  {
    uuid: '872cde09-809a-4f48-8bf5-88f37127cb55',
    parent_uuid: null,
    slot: null,
    component_id: 'js.card',
    component_version: 'a681ae184a8f6b7f',
    inputs: { title: 'Hello' },
    label: 'Card',
  },
  {
    uuid: '87106237-b8d8-4e19-82f7-c780ad24feb5',
    parent_uuid: '872cde09-809a-4f48-8bf5-88f37127cb55',
    slot: 'body',
    component_id: 'js.text',
    component_version: 'b1e991f726a2a266',
    inputs: { content: 'World' },
    label: 'Text',
  },
];

const jsonRenderSpec = canvasTreeToSpec(components);
```

#### `specToCanvasTree`

Converts a json-render spec back to a flat Drupal Canvas component tree. Strips
the synthetic `canvas:component-tree` wrapper if present, so multi-root trees
round-trip cleanly.

```js
import { specToCanvasTree } from 'drupal-canvas/json-render-utils';

const jsonRenderSpec = {
  root: 'card',
  elements: {
    card: {
      type: 'js.card',
      props: { title: 'Hello' },
      slots: { body: ['text'] },
    },
    text: {
      type: 'js.text',
      props: { content: 'World' },
    },
  },
};

const canvasComponentTree = specToCanvasTree(jsonRenderSpec);
```

#### `renderSpec`

Renders a json-render spec generated from canvas component tree using
`canvasTreeToSpec`. The synthetic `canvas:component-tree` wrapper used for
multi-root trees is handled internally and renders transparently. Unknown
component types render nothing.

```jsx
import { renderSpec } from 'drupal-canvas/json-render-utils';

import registry from './registry';

const spec = {
  root: 'card',
  elements: {
    card: {
      type: 'js.card',
      props: { title: 'Hello' },
      children: ['text'],
    },
    text: {
      type: 'js.text',
      props: { content: 'World' },
    },
  },
};

const rendered = renderSpec(spec, registry);
```

#### `renderCanvasTree`

Renders a Canvas component tree. Requires a `ComponentRegistry` for mapping
component IDs to React components. Converts the tree to a json-render spec
internally using `canvasTreeToSpec` and delegates to `renderSpec`. Unknown
component types render nothing.

```jsx
import { JsonApiClient } from 'drupal-canvas';
import { renderCanvasTree } from 'drupal-canvas/json-render-utils';
import useSWR from 'swr';

import registry from './registry';

const client = new JsonApiClient();

export function CanvasPage({ id }) {
  const { data: page } = useSWR(
    ['canvas_page--canvas_page', id],
    ([type, id]) => client.getResource(type, id),
  );

  if (!page) return null;

  return renderCanvasTree(page.components, registry);
}
```

#### `defineComponentRegistry`

Defines a component registry by dynamically importing each component's
JavaScript entry file. Accepts an array of objects with `name` and `jsEntryPath`
— compatible with `DiscoveryResult.components` from `@drupal-canvas/discovery`.
Each module's default export is expected to be a render function. Components
without a JS entry or without a default function export are skipped.

```js
import { defineComponentRegistry } from 'drupal-canvas/json-render-utils';
import { discoverCodeComponents } from '@drupal-canvas/discovery';

const discovery = await discoverCodeComponents({ scanRoot: './src' });
const registry = await defineComponentRegistry(discovery.components);
```

#### `defineComponentCatalog`

Defines a complete [json-render](https://json-render.dev) catalog from component
metadata. Converts props from JSON Schema (as defined in `component.yml`) to Zod
schemas. The returned catalog can be used with `catalog.prompt()` for AI prompt
generation, `catalog.validate()` for spec validation, etc.

```js
import { defineComponentCatalog } from 'drupal-canvas/json-render-utils';
import {
  discoverCodeComponents,
  loadComponentsMetadata,
} from '@drupal-canvas/discovery';

const discovery = await discoverCodeComponents({ scanRoot: './src' });
const metadata = await loadComponentsMetadata(discovery);
const catalog = defineComponentCatalog(metadata);
const systemPrompt = catalog.prompt();
```

## Base Components

### FormattedText

A built-in component to render text with trusted HTML using
[`dangerouslySetInnerHTML`](https://react.dev/reference/react-dom/components/common#dangerously-setting-the-inner-html).
The content is safe when processed through Drupal's filter system that is
[correctly configured](https://www.drupal.org/docs/administering-a-drupal-site/security-in-drupal/configuring-text-formats-aka-input-formats-for-security).

```jsx
import { FormattedText } from 'drupal-canvas';

export default function Example() {
  return (
    <FormattedText>
      <em>Hello, world!</em>
    </FormattedText>
  );
}
```

### Image

A built-in component for automatic image optimization, responsive behavior, and
modern loading techniques for code components.

The `Image` component is a wrapper around the
[next-image-standalone](https://www.npmjs.com/package/next-image-standalone)
library, preconfigured with a loader to work with the zero-config dynamic image
style in Drupal Canvas.

```jsx
import { Image } from 'drupal-canvas';

export default function MyComponent({ photo }) {
  return (
    <Image
      src={photo.src}
      alt={photo.alt}
      width={photo.width}
      height={photo.height}
    />
  );
}
```

## Development

The following scripts are available for developing this package:

| Command      | Description                                                              |
| ------------ | ------------------------------------------------------------------------ |
| `build`      | Compile to the `dist` folder for production use.                         |
| `dev`        | Compile to the `dist` folder for development while watching for changes. |
| `type-check` | Run TypeScript type checking without emitting files.                     |
| `test`       | Run tests.                                                               |
