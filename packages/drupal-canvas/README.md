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
The main entity is the primary Drupal entity (e.g. article, canvas_page, blog) associated with the current page.
Access main entity metadata of the page you are on with `getPageData`. This can be used to construct JSON:API parameters for requests.
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
