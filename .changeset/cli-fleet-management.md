---
'@drupal-canvas/cli': minor
---

Publish two supported entry points for tools that build on the CLI:
`@drupal-canvas/cli/internals` for the API client, configuration and project
discovery, and `@drupal-canvas/cli/internals/build` for the component build and
upload pipeline. They are separate because the build pulls in Vite, Tailwind and
their WebAssembly, so a tool that only talks to the Canvas API loads in
milliseconds instead of seconds. Every name exported from them is a
compatibility commitment, locked by a test; anything else remains private.
