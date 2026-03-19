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
