---
"@drupal-canvas/headless": minor
"@drupal-canvas/headless-astro": patch
"@drupal-canvas/headless-nuxt": patch
"@drupal-canvas/headless-tanstack-start": patch
---

Unify the request-time `frame-ancestors` policy across framework adapters.
When `CANVAS_EDITOR_ORIGINS` is unset, admit `'self'`, the valid
`CANVAS_SITE_URL` origin and the draft-session editor origin. An explicit
whitespace- or comma-separated list replaces both defaults; empty or invalid
configuration never restores them.

Normalize and deduplicate sources centrally. Reject credentialed/non-HTTP(S)
URLs, wildcard or delimiter-bearing hosts, and literal IPv6 addresses. DNS
hostnames resolving to IPv6 remain supported. Application-owned
`frame-ancestors` stays authoritative; other CSP directives are preserved.
The Astro and TanStack Vite integrations also load `CANVAS_EDITOR_ORIGINS`
from environment files, including explicit empty values.

This changes embedding policy only, not draft authentication or message trust.
