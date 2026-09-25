# @drupal-canvas/headless-react

Shared React bindings for the Drupal Canvas Headless SDK.

The framework adapters (`@drupal-canvas/headless-next`,
`@drupal-canvas/headless-tanstack-start`) re-export these components with their
router wiring filled in — use those in an app. An app on a React framework
without an adapter can use this package directly; it depends only on React and
`@drupal-canvas/headless`.

## Installation

```bash
npm install @drupal-canvas/headless-react
```

## Usage

### Draft session

`<DraftSession>` runs the in-editor session renewal protocol and drives your
banner UI through a render prop. It takes the session state gathered on the
server, plus two framework hooks:

- `path` — the current pathname from the router, reported to the embedding
  editor and carried by the renew link.
- `refreshData` — the framework's server-data refresh (`router.refresh()` in
  Next.js). Optional: without it the component resets its expiry timer in place
  from the renew endpoint's response.

### Component rendering

`<CanvasComponentTree>` renders the structured content returned by
`fetchPage()`. Registry keys are `component.yml` machine names:

```tsx
import { CanvasComponentTree } from '@drupal-canvas/headless-react';

import HelloCard from './components/canvas/hello-card';

<CanvasComponentTree
  tree={page.content}
  components={{ 'hello-card': HelloCard }}
/>;
```

Pass `context={page.context}` so registered components can read the page and
site context through `usePageContext()` and `useSiteContext()` from
`drupal-canvas/react`: the renderer wraps the tree in `CanvasContextProvider`.
An explicit `context` prop takes precedence over an outer provider, `null`
values included; when omitted, an outer `CanvasContextProvider` is inherited.

`useJsonApiClient()` is served from the nonsecret JSON:API runtime configuration
the SDK's server integration prepares (`getJsonApiRuntimeConfig()`): either the
`jsonApi` prop, or the nearest `JsonApiRuntimeProvider` (framework adapters
supply one, for example the Next.js `CanvasRuntime` server component; TanStack
Start applications render it in the root route from loader data). Without
configuration an outer `JsonApiClientProvider` is inherited, and without that
the hook reports the missing provider. In the browser the client sends requests
through the application's same-origin proxy. Server rendering creates its own
client from the same configuration: public rendering gets a direct,
unauthenticated client; a live draft session gets the same non-null, draft-aware
client the browser gets, so SWR keys stay enabled, prefetched fallback data
renders into the initial HTML, and hydration does not mismatch — but that client
performs no network requests. Draft data is not fetched during server rendering:
a request made while rendering fails with `ServerRenderingDraftFetchError`,
which names the fix. Prefetch draft data on the server with the SDK's
`getClient()` and supply it as SWR fallback data (see
`@drupal-canvas/headless`); SWR fetches in the browser after hydration, through
the proxy. This contract is the same for every React adapter.

Named Canvas slots become React props with rendered `ReactNode` values; a
`default` slot becomes `children`. Drupal markup strings are inserted as trusted
HTML. Because React does not natively support rendering comment nodes, draft
trees use layout-neutral `<template>` markers for Canvas boundaries, which may
affect structural CSS selectors. Published trees remain marker-free.
