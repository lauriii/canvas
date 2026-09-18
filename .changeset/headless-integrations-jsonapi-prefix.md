---
'@drupal-canvas/headless-next': minor
'@drupal-canvas/headless-tanstack-start': minor
---

Automatically discover the site's JSON:API prefix so sites using a non-default
prefix (e.g. `/api`) work without configuration.

`getPublicClient()` and `getDraftClient()` are now async — `await` them like
`getClient()`.
