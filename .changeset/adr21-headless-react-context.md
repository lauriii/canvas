---
'@drupal-canvas/headless-react': minor
---

`CanvasComponentTree` accepts `context` (from `fetchPage()`'s `page.context`)
and `jsonApi` (from the SDK's `getJsonApiRuntimeConfig()`) props and mounts
the `drupal-canvas` context and JSON:API client providers for registered
components. An explicit `context` prop takes precedence over an outer
`CanvasContextProvider`; without either, the hooks report missing context.
Server rendering gets the same draft-aware client as the browser (SWR fallback
data renders, hydration matches) without network access in a draft session:
draft requests made while rendering fail with `ServerRenderingDraftFetchError`,
which points to `getClient()` prefetching and SWR fallback data.
