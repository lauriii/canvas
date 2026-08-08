---
'@drupal-canvas/cli': minor
---

Add a supported `@drupal-canvas/cli/internals` entry point exposing the build,
upload and API code, so other tools can build on the CLI instead of reaching
into `dist/` by path or duplicating it. This is what the new `canvas-fleet`
package uses to distribute one component library to many sites. Everything
exported there is a compatibility commitment; anything else remains private.
