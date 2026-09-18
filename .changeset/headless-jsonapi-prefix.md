---
'@drupal-canvas/headless': minor
---

Resolve the JSON:API prefix from the site's now-public
`/canvas/api/v0/site-data` endpoint (previously editor-only) instead of always
using the `/jsonapi` default, so sites serving JSON:API from a non-default
prefix (e.g. `/api`) work without configuration. When that endpoint is
unreachable, the `CANVAS_JSONAPI_PREFIX` environment variable (or a config
override) applies as a fallback.

`getPublicClient()` and `getDraftClient()` are now async — `await` them like
`getClient()`.
