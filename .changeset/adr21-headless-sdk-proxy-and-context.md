---
'@drupal-canvas/headless': minor
---

Make React Code Components portable to headless frontends.

- `fetchPage()` returns `page.context`, the page and site context React Code
  Components read through the `drupal-canvas` context hooks; sites without the
  context API yield `null` page and site context.
- `getPublicClient()`, `getDraftClient()`, and `getClient()` create the shared
  `drupal-canvas` client (`createJsonApiClient()`) instead of a separate
  subclass. Draft reads resolve working copies before serialization, and the
  default serialization now matches `drupal-canvas`.
- `CANVAS_JSONAPI_URL` overrides JSON:API discovery with a full upstream URL;
  `CANVAS_JSONAPI_PROXY_PATH` sets the application's proxy path (default
  `/api/canvas/jsonapi`).
- `createJsonApiProxyHandler()` and `DraftServer.handleJsonApiProxy()` add a
  same-origin JSON:API proxy that authenticates from the draft session, keeps
  requests within the configured backend's JSON:API and path-translation
  endpoints, validates redirects, refuses cross-site state-changing requests,
  returns a session error for expired sessions, and keeps draft responses out
  of shared caches.
- `DraftServer.getJsonApiRuntimeConfig()` prepares the nonsecret configuration
  browser clients are created from.

The proxy surfaces a session token Drupal rejects (401) as the session error,
passes 304 through, merges Drupal's `Vary` with `Cookie`, and only rewrites
redirect statuses. `CANVAS_JSONAPI_URL` overrides JSON:API requests only; path
translation stays on `CANVAS_SITE_URL`.

`CANVAS_JSONAPI_SITE_URL` gives a JSON:API URL on another site its site base,
so language-prefixed reads and the proxy resolve under it.
