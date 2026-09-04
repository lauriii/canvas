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

## Static generation

`nuxt generate` works: the module keeps the component preview route out of the
prerender crawl, and in a static build the `<DraftSession>` component skips the
session fetch, renders nothing, and logs one console notice that draft preview
is unavailable. Editors preview drafts against the server-rendered deployment of
the same app instead.

The generate crawler only finds pages something links to, so list the
Drupal-known paths explicitly until the module wires this up itself —
`fetchStaticPaths()` from `@drupal-canvas/headless/server` returns them from
Drupal's route inventory:

```ts
export default defineNuxtConfig({
  modules: ['@drupal-canvas/headless-nuxt'],
  hooks: {
    async 'nitro:config'(nitroConfig) {
      const { fetchStaticPaths, resolveDraftConfig } =
        await import('@drupal-canvas/headless/server');
      (nitroConfig.prerender ??= {}).routes =
        await fetchStaticPaths(resolveDraftConfig());
    },
  },
});
```

Do not put pages behind Nitro's `swr` or `isr` route rules without keying the
cache off the draft cookies: a response rendered for a draft session and cached
once would be served to anonymous visitors. Cache public pages only.

## Data access

Data access happens in Nitro server routes, where the draft session cookies
live: `getClient(event)` returns the draft-aware JSON:API client and
`fetchPage(event, path)` fetches rendered content, both from
`@drupal-canvas/headless-nuxt/server`. Pages consume those routes with
`useFetch()`, which forwards the request's cookies during SSR. Render
`page.content` directly and pass the complete `page.head` object reactively to
`useHead()`. Handle `PageRedirect` before page rendering with `navigateTo()`.
