---
'@drupal-canvas/headless-next': minor
'@drupal-canvas/headless-tanstack-start': minor
'@drupal-canvas/headless-nuxt': minor
'@drupal-canvas/headless-astro': minor
---

Mount the same-origin JSON:API proxy for portable Code Components and expose
the JSON:API runtime configuration (`getJsonApiRuntimeConfig()`) browser
clients are created from. The React renderers accept `context={page.context}`
so `usePageContext()` and `useSiteContext()` work in headless components.
Server rendering follows one contract in every React adapter: prefetch draft
data with `getClient()` and supply SWR fallback data; the hook's client is the
same on both sides of hydration.
