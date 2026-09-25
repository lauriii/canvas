# @drupal-canvas/headless-nuxt

Nuxt adapter for the Drupal Canvas Headless SDK.

It gives a Nuxt app draft preview bound to the editing user, in-place session
renewal inside the Canvas editor frame, and the component metadata endpoint
Drupal Canvas registers the app's components from.

## Installation

```bash
npm install @drupal-canvas/headless-nuxt
```

Set the `CANVAS_SITE_URL` environment variable to your Drupal site URL.

## Usage

**1. nuxt.config.ts** — the module mounts the draft routes and the component
metadata endpoint, registers the CSP `frame-ancestors` response hook, compiles
the SDK packages into both the Vue and Nitro builds, and writes the component
manifest at build time:

```ts
export default defineNuxtConfig({
  modules: ['@drupal-canvas/headless-nuxt'],
});
```

Configure under the `drupalCanvas` key: `injectRoutes: false` to mount the
runtime handlers at paths of your own, `componentsRoutePath` to move the
metadata endpoint. The module also mounts the same-origin JSON:API proxy at
`/api/canvas/jsonapi/**` (`CANVAS_JSONAPI_PROXY_PATH`), through which browser
code reaches Drupal with the draft session's authorization; the
`routes/jsonapi-proxy` subpath export mounts it by hand.

**2. Session banner** — render the globally registered `<DraftSession>`
component in the app shell with the banner markup in its slot. The component
gathers the session state and runs the renewal protocol; it owns the visibility
of the marked children:

```vue
<DraftSession>
  <div data-draft-session-view="active">Draft mode is active.</div>
  <div data-draft-session-view="expired">
    Draft session expired.
    <a data-draft-session-renew-link>Renew session</a>
  </div>
</DraftSession>
```

**3. Component tree** — pass the structured content returned by `fetchPage()` to
the globally registered `<CanvasComponentTree>`:

```vue
<CanvasComponentTree :tree="page.content" />
```

The module supplies a registry of every discovered component implementation, and
the renderer consumes it automatically. During development the registry updates
when components are added, removed, or renamed.

## Editor origins and CSP

By default, `frame-ancestors` admits `'self'`, the `CANVAS_SITE_URL` origin and
the draft-session editor origin. Set `CANVAS_EDITOR_ORIGINS` to a comma- or
whitespace-separated list of HTTP(S) URLs to replace both defaults. An empty or
entirely invalid list admits only `'self'`. Origins are normalized and
deduplicated; credentials, wildcards and literal IPv6 are rejected. For IPv6,
use a DNS hostname.

The Nitro `beforeResponse` hook merges CSP, preserving other directives and
application-owned `frame-ancestors`, including repeated headers. Use
server-rendered previews. Reconcile later hooks/hosting CSP separately: multiple
policies intersect. Verify deployed headers. This policy controls embedding, not
draft authorization.

Both variables are read from server `process.env` per response, not public
runtime config. Nuxt loads `.env` during dev/build; supply production
environment values separately. Restart after environment changes, or
rebuild/redeploy if the Nitro preset or host embeds them.

## Data access

Data access happens in Nitro server routes, where the draft session cookies
live: `getClient(event)` returns the draft-aware JSON:API client and
`fetchPage(event, path)` fetches rendered content, both from
`@drupal-canvas/headless-nuxt/server`. Pages consume those routes with
`useFetch()`, which forwards the request's cookies during SSR. Render
`page.content` directly and pass the complete `page.head` object reactively to
`useHead()`. Handle `PageRedirect` before page rendering with `navigateTo()`.

The client's JSON:API prefix is resolved from the site's public site-data
endpoint (fetched once per server instance), so sites serving JSON:API from a
non-default prefix (e.g. `/api`) work without configuration. When that endpoint
is unreachable, the `CANVAS_JSONAPI_PREFIX` environment variable applies, then
the `/jsonapi` default; `CANVAS_JSONAPI_URL` sets a full upstream URL that takes
precedence over discovery. `getPublicClient()` and `getDraftClient()` are async
for the same reason: `await` them like `getClient()`. All three create the
shared `drupal-canvas` client (`createJsonApiClient()`), which deserializes
responses with `DefaultSerializer`.

`fetchEntity(event, { type, id, viewMode })` renders one content entity without
page-level route or head data. Use it for embedded renders such as teaser cards.

`page.context` carries the page and site context (title, breadcrumbs, primary
entity, branding) Drupal generated for the routed page. To fetch JSON:API
content from the browser, build the shared client from the nonsecret runtime
configuration the session route answers as `jsonApi`:

```ts
import { createJsonApiClient } from 'drupal-canvas/jsonapi-client';

const { data: session } = await useFetch('/api/draft/session');
const client = createJsonApiClient({
  ...session.value.jsonApi,
  credentials: 'same-origin',
});
```

Requests go through the proxy; an expired preview session surfaces as
`DraftSessionError` instead of public content.
