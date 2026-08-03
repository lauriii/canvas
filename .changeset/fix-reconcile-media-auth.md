---
"@drupal-canvas/cli": patch
---

Fix reconcile-media to support bearer token authentication

The reconcile-media command now uses ensureAuthConfig() instead of directly requiring clientId/clientSecret, allowing it to work with CANVAS_ACCESS_TOKEN bearer token authentication like other commands (push, pull, build).
