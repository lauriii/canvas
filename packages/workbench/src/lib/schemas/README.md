# Workbench authored spec schemas

This directory contains JSON Schemas for authored Workbench formats that later
normalize into [json-render](https://json-render.dev) (`@json-render/react`)
specs.

- `page-spec.schema.json` defines the authored page format.
- `component-mocks.schema.json` defines the authored component mock file format,
  including the top-level `mocks` array.

These schemas intentionally keep `props` permissive. Component-level prop
validation should continue to come from discovered component metadata and
runtime catalog validation, rather than from these generic authored-format
schemas.

`brand-kit.schema.json` defines `canvas.brand-kit.json` (fonts and colors).
The CLI writes a `$schema` reference pointing at the published copy —
`https://unpkg.com/@drupal-canvas/workbench/dist/client/src/lib/schemas/brand-kit.schema.json`
— into files it creates, so editors validate and autocomplete them; add the
same line to a hand-created file to get the same. `canvas validate` checks
the file against this schema plus the semantic rules a schema cannot express
(two keys naming the same variable, numeric ranges inside color strings,
font files existing on disk).
