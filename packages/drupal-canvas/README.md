# Drupal Canvas Code Components Utils

Utilities and base components for building Drupal Canvas Code Components.

React context hooks/providers are exported from `drupal-canvas/react`, together
with `CanvasContextProviderProps` and `JsonApiClientProviderProps`. Context data
and client types remain at the root. Existing components (`FormattedText`,
`Image`, `Region`, `RegionsProvider`), utilities and legacy subpaths are
unchanged; authoring helpers remain on `drupal-canvas/json-render-utils`.

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

### `usePageContext` and `useSiteContext`

Read the current page and site data in a React Code Component. Both hooks work
in Drupal-rendered Code Components, Canvas Workbench previews, and React-based
headless frontends: the rendering integration establishes the provider, and the
hooks read it synchronously. They return `null`, with one actionable console
warning, when no provider is mounted or the integration supplied no such data.

```jsx
import { usePageContext, useSiteContext } from 'drupal-canvas/react';

export default function PageHeader() {
  const page = usePageContext();
  const site = useSiteContext();

  if (!page || !site) return null;

  return (
    <header>
      <a href={site.branding.homeUrl}>{site.branding.siteName}</a>
      <h1>{page.pageTitle}</h1>
    </header>
  );
}
```

- Page context (`PageContext`): `pageTitle`, `breadcrumbs`, and `mainEntity`
  (the primary Drupal entity, `null` on routes without one), including language
  and translation information. See
  [Main entity metadata](https://project.pages.drupalcode.org/canvas/code-components/data-fetching#main-entity-metadata).
- Site context (`SiteContext`): `branding`, the Drupal `baseUrl`, and
  `themeAssets`. Headless frontends receive empty theme asset URLs; that is
  valid site context, so shared components must handle empty asset URLs.

Call the hooks unconditionally at the top level of a function component or
custom hook (React's rules of hooks) and handle `null` results.

To reach components outside a Canvas tree, such as a site header in a headless
application, wrap them in `CanvasContextProvider`:

```jsx
import { CanvasContextProvider } from 'drupal-canvas/react';

<CanvasContextProvider context={page.context}>
  <SiteHeader />
</CanvasContextProvider>;
```

The `CanvasContext` type describes the provider's value:
`{ page: PageContext | null; site: SiteContext | null }`.

### `getPageData` and `getSiteData` (deprecated)

`getPageData()` and `getSiteData()` are deprecated in favor of
`usePageContext()` and `useSiteContext()`. They keep working in Drupal-rendered
Code Components and Canvas Workbench previews, where they read `drupalSettings`,
and they still report their data to the code editor's "Component data" panel.
Outside those environments they throw an error that names the replacement API.
Components used in both frontend modes must migrate to the hooks; `canvas pull`
migrates safe calls automatically (see the Canvas CLI).

```js
import { getPageData, getSiteData } from 'drupal-canvas';

const { pageTitle, breadcrumbs, mainEntity } = getPageData();
const { baseUrl, branding } = getSiteData();
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

### `useJsonApiClient`

Read a configured
[JSON:API client](https://www.npmjs.com/package/@drupal-api-client/json-api-client)
from context. The Drupal island renderer, the headless React renderer, and both
Workbench preview paths provide it; the hook never fetches data or creates a
client on render. Use it with SWR or another fetching library:

```jsx
import { useJsonApiClient } from 'drupal-canvas/react';
import { DrupalJsonApiParams } from 'drupal-jsonapi-params';
import useSWR from 'swr';

export default function List() {
  const client = useJsonApiClient();
  const { data, error } = useSWR(client ? 'articles' : null, () =>
    client.getCollection('node--article', {
      queryString: new DrupalJsonApiParams()
        .addInclude(['field_tags'])
        .getQueryString(),
    }),
  );

  if (error) return 'An error has occurred.';
  // Test for data, not `isLoading`: with prefetched SWR fallback data the
  // data is present while SWR still reports loading during revalidation.
  if (!data) return 'Loading...';
  return (
    <ul>
      {data.map((article) => (
        <li key={article.id}>{article.title}</li>
      ))}
    </ul>
  );
}
```

The hook returns `null`, with one console warning, when no provider is mounted.
In Drupal previews the client reads working copies (the `rel:working-copy`
resource version) through the editor's session; in headless browsers it reaches
Drupal through the application's same-origin proxy, authenticated from the draft
preview session. Replacing the provided client does not clear SWR caches.

For portable components, use this hook rather than Drupal globals or a client
with hardcoded URLs or credentials. Drupal, Workbench, and headless React
integrations supply the client; the hook does not detect the environment. See
[Writing portable components](../../docs/user/src/content/docs/code-components/data-fetching.mdx#writing-portable-components)
for an SWR example and server-rendering guidance.

[Drupal core's JSON:API module](https://www.drupal.org/docs/core-modules-and-themes/core-modules/jsonapi-module)
must be enabled.

Provide a client explicitly for components outside a Canvas tree with
`JsonApiClientProvider`:

```jsx
import { JsonApiClientProvider } from 'drupal-canvas/react';

<JsonApiClientProvider client={client}>
  <SiteHeader />
</JsonApiClientProvider>;
```

### `createJsonApiClient` (`drupal-canvas/jsonapi-client`)

The shared, framework-agnostic client implementation rendering integrations and
the Canvas Headless SDK build on. It extends
`@drupal-api-client/json-api-client` with `DefaultSerializer`, draft reads at a
configured resource version (collection items are hydrated with their working
copies before serialization; raw responses bypass this), mapping of browser
requests to a same-origin proxy (including absolute pagination links and the
Decoupled Router endpoint), `DraftSessionError` for rejected preview sessions
(the proxy's session error, or a 401 answered to a direct request that carried
the session credentials), and caches separated by resource version and session
scope: a client that may carry a session (authentication, cookies — explicit or
a browser's same-origin default — the proxy, a custom `fetch` transport, a
preview or a resource version) caches only with an explicit `cacheScope`, so the
legacy client and any transport-injected client share no cache without a
caller-provided scope. A 401 is a rejected session when the request carried the
configured credentials (the Authorization header, cookies, or a transport
declared with `fetchAuthenticates`); `disableAuthentication` opts a request out,
and failing to obtain or renew credentials is a rejected session too. Draft
collection reads hydrate each item with its working copy, keeping the read's
locale, sparse fieldsets, includes, and an explicitly selected resource version;
the working copies' included resources are merged into the document, and a
resource that is itself primary is never duplicated in `included`, so
relationships resolve to the selected working copy. URLs keep the backend's site
path (`https://host/sub/fr/jsonapi/...`, also for the Decoupled Router and index
lookups); `apiUrl` under `baseUrl` is a prefix override, and `apiUrl` on another
site is a foreign JSON:API base: with `apiSiteUrl` (that site's base URL,
install path included) a locale prefix goes between them
(`https://api.example/mount/fr/api`), without it no locale prefix applies; the
Decoupled Router stays under `baseUrl` either way.

```js
import { createJsonApiClient } from 'drupal-canvas/jsonapi-client';

const client = createJsonApiClient({
  baseUrl: 'https://drupal.example',
  apiPrefix: 'jsonapi',
  // Browser clients in headless apps go through the app's proxy.
  proxyUrl: '/api/canvas/jsonapi',
  resourceVersion: 'rel:working-copy',
  preview: true,
});
```

`DefaultSerializer` and `createCache` are re-exported from
`drupal-canvas/jsonapi-client` unchanged.

### `JsonApiClient` (deprecated)

`new JsonApiClient()` is deprecated in favor of `useJsonApiClient()` in React
Code Components and the Headless SDK's `getClient()` in headless server code. It
keeps working in Drupal-rendered Code Components and Canvas Workbench previews,
where it is configured from `drupalSettings`, and throws an error naming the
replacement APIs elsewhere, even when a base URL is supplied.

```jsx
import { JsonApiClient } from 'drupal-canvas';

const client = new JsonApiClient();
```

You can override the `baseUrl` and any default options:

```js
const client = new JsonApiClient('https://drupal-api-demo.party', {
  serializer: undefined,
  cache: undefined,
});
```

### Migrating from the deprecated APIs

Replace `getPageData()`, `getSiteData()`, and `new JsonApiClient()` with
`usePageContext()`, `useSiteContext()`, and `useJsonApiClient()` in function
components or custom hooks. Call hooks unconditionally at the top level before
any possible return; handle missing context or clients. Outside components and
custom hooks, use the Headless SDK's page context data or `getClient()` in
headless server code; pass data or a client to browser helpers. Preserve output,
types, hook order, and access controls. Never expose credentials.

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
    component_version: 'd34b93534777207a',
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
import { discoverCanvasProject } from '@drupal-canvas/discovery';

const discovery = await discoverCanvasProject({ componentRoot: './src' });
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
  discoverCanvasProject,
  loadComponentsMetadata,
} from '@drupal-canvas/discovery';

const discovery = await discoverCanvasProject({ componentRoot: './src' });
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

Drupal generates no derivative images for an image its image toolkit cannot
process, an SVG image for example. Such an image is rendered as-is, without a
`srcset`. An SVG image that specifies neither its dimensions nor a `viewBox` is
rendered without `width` and `height` too, and is then sized by the browser.

### Region / RegionsProvider (deprecated)

Deprecated: theme-global regions were replaced by page variants, which compose a
page from a single component tree. Both components keep working for
compatibility, but no new region integration is added.

Render Drupal Canvas global regions inside a layout component.
`<Region name="..." />` slots in the region whose machine name matches `name`,
and `<RegionsProvider regions={...}>` supplies the region node map. On the
Drupal side, region placement is handled by the active theme; these components
are used by standalone renderers (such as
[Workbench](https://project.pages.drupalcode.org/canvas/code-components/workbench/regions))
that compose a page with its surrounding regions on their own.

```jsx
import { Region } from 'drupal-canvas';

export default function Layout({ children }) {
  return (
    <>
      <Region name="header" />
      <main>{children}</main>
      <Region name="footer" />
    </>
  );
}
```

Pass a `fallback` to render placeholder content when a region is not provided:

```jsx
<Region name="sidebar" fallback={<aside>No sidebar configured</aside>} />
```

When composing a renderer outside of Drupal or Workbench, wrap the tree in
`RegionsProvider` with a map of region machine names to React nodes:

```jsx
import { RegionsProvider } from 'drupal-canvas';

<RegionsProvider regions={{ header: <SiteHeader />, footer: <SiteFooter /> }}>
  <Layout>{pageContent}</Layout>
</RegionsProvider>;
```

## Development

The following scripts are available for developing this package:

| Command      | Description                                                              |
| ------------ | ------------------------------------------------------------------------ |
| `build`      | Compile to the `dist` folder for production use.                         |
| `dev`        | Compile to the `dist` folder for development while watching for changes. |
| `type-check` | Run TypeScript type checking without emitting files.                     |
| `test`       | Run tests.                                                               |
