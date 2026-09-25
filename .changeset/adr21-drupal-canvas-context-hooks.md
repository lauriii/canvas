---
'drupal-canvas': minor
---

Add the shared runtime APIs for React Code Components that run in Drupal and in
React-based headless frontends. New React hooks/providers and their provider
prop types are exported from `drupal-canvas/react`; existing components and
utility import paths remain unchanged. Framework-neutral data/client types
remain at the root.

- `usePageContext()` and `useSiteContext()` replace `getPageData()` and
  `getSiteData()`; `CanvasContextProvider` supplies the values, and the
  `PageContext`, `SiteContext`, and `CanvasContext` types describe them.
- `useJsonApiClient()` and `JsonApiClientProvider` replace `new JsonApiClient()`
  in components; `createJsonApiClient()` (from `drupal-canvas/jsonapi-client`)
  is the shared, framework-agnostic client with draft reads at a resource
  version, same-origin proxy mapping, `DraftSessionError`, and scoped caches.
- `getPageData()`, `getSiteData()`, and `new JsonApiClient()` are deprecated.
  They keep working in Drupal-rendered Code Components and Canvas Workbench
  previews and throw an actionable migration error elsewhere.
- `Region` and `RegionsProvider` are deprecated in favor of page variants.

The shared client treats a 401 answered to an authenticated direct request as
a rejected session (`DraftSessionError`), caches authenticated responses only
with an explicit session-unique `cacheScope`, keeps the applicable read
options (locale, sparse fieldsets, includes, an explicit resource version)
for working-copy item reads and merges their included resources, and accepts
`apiUrl` for JSON:API served from a separate base URL while the Decoupled
Router stays on `baseUrl`.

Caching is disabled without an explicit scope for every client that carries
a session (cookies, the proxy, previews, resource versions), 401s are
classified by the credentials the request actually carried, selected working
copies take precedence over included copies of the same resource, and URLs
keep the backend's site path for JSON:API, the Decoupled Router and index
lookups.

Shared caching also stays off, absent an explicit scope, for the legacy
client, custom transports and browser clients with default credentials; a
401 is classified by the configured credential lifecycle (with
`fetchAuthenticates` for custom transports) and credential acquisition
failures never downgrade a draft read; `apiSiteUrl` localizes a JSON:API base
on another site.
