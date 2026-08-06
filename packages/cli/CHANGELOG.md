# @drupal-canvas/cli

## 0.21.3

### Patch Changes

- 7bcf0cc: Fix reconcile-media to support bearer token authentication

  The reconcile-media command now uses ensureAuthConfig() instead of directly
  requiring clientId/clientSecret, allowing it to work with CANVAS_ACCESS_TOKEN
  bearer token authentication like other commands (push, pull, build).

## 0.21.2

### Patch Changes

- cbb1a53: Fix content template creation by omitting the unsupported `label`
  property from create requests.

## 0.21.1

### Patch Changes

- d71fdd4: Preserve resolved media and link props when pulling and pushing
  global regions.
