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
metadata endpoint, registers the CSP `frame-ancestors` middleware, compiles the
SDK packages into both the Vue and Nitro builds, and writes the component
manifest at build time:

```ts
export default defineNuxtConfig({
  modules: ['@drupal-canvas/headless-nuxt'],
});
```

Configure under the `drupalCanvas` key: `injectRoutes: false` to mount the
runtime handlers at paths of your own, `componentsRoutePath` to move the
metadata endpoint.

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

## Data access

Data access happens in Nitro server routes, where the draft session cookies
live: `getClient(event)` returns the draft-aware JSON:API client and
`fetchPage(event, path)` fetches rendered content, both from
`@drupal-canvas/headless-nuxt/server`. Pages consume those routes with
`useFetch()`, which forwards the request's cookies during SSR. Render
`page.content` directly and pass the complete `page.head` object reactively to
`useHead()`. Handle `PageRedirect` before page rendering with `navigateTo()`.

## Content invalidation

When an editor publishes in Drupal, Canvas calls a webhook so the Nuxt app can
drop its cached copies of the affected pages. Mount the revalidation handler at
a server route and set the shared secret.

**1. Mount the handler** — create `server/routes/api/canvas/revalidate.post.ts`:

```ts
import { createRevalidateEventHandler } from '@drupal-canvas/headless-nuxt/server';

export default createRevalidateEventHandler();
```

**2. Set the secret** — `CANVAS_PUBLISH_WEBHOOK_SECRET` must match the site's
`$settings['canvas_headless_publish_webhook_secret']`. The handler verifies the
`X-Canvas-Signature` HMAC on every request: it answers 401 when the signature
does not match and 500 when the secret is not configured.

**3. Cache pages under the `canvas` group** — Nitro's cache is key-based, not
tag-based, so there is no direct equivalent of Next's tag revalidation. The
default handler clears one documented cache group instead, so tag your Canvas
page caches with `group: 'canvas'`:

```ts
export default defineCachedEventHandler(handler, {
  group: 'canvas',
  swr: true,
});
```

A publish then clears every Canvas page cache at once, and only those; unrelated
Nitro caches are left untouched. With stale-while-revalidate (a `swr: true`
cached handler, or `routeRules: { '/**': { swr: true } }` in `nuxt.config.ts`)
the last render keeps serving while the next request regenerates the page, so a
publish never blocks a visitor.

This is coarser than the Next.js adapter, which revalidates the exact tags a
publish touched. For precise invalidation, pass a `revalidate` callback and map
the payload's Drupal cache tags to your own cache keys:

```ts
export default createRevalidateEventHandler({
  revalidate: async ({ tags }) => {
    // Invalidate exactly the cache keys these tags map to.
  },
});
```

### Purging a CDN by surrogate key

A CDN-fronted deploy can purge by cache tag instead. Set the `Surrogate-Key`
response header from a page's cacheability tags when you render it, and the CDN
keys its cached objects by the same tags the publish webhook carries:

```ts
import { setResponseHeader } from 'h3';
import { surrogateKeyHeader } from '@drupal-canvas/headless-nuxt/server';

// In the server route that renders the page, after handling any redirect:
const key = surrogateKeyHeader(page);
if (key) {
  setResponseHeader(event, 'Surrogate-Key', key);
}
```

`surrogateKeyHeader()` returns an empty string when the page has no tags, so the
guard skips the header in that case.
