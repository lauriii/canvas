# 24. Import map entries for Canvas's own use

Date: 2026-11-16

## Status

Accepted

## Context

Canvas's import map lists libraries that Code Components import, such as
`preact` and `swr`. `astro-hydration` builds them, and the tools that build
Code Components treat them as externals, so a Code Component's build leaves
their imports to the import map instead of bundling a copy of its own.

Some modules are needed by parts of Canvas that run separately, and must be
the same copy in each, without being libraries for Code Components. An
island's hydration data, for example, is encoded during server-side
rendering, which loads modules through the same import map, and decoded by
the island client renderer in the browser. Were they different copies, their
formats could differ.

Giving such a module an import map entry under the name of the third-party
package it wraps would make that package a library for Code Components. A
Code Component that imports the package would get Canvas's version instead of
the one it was built with, and Canvas would have to keep that version stable
for Code Components.

## Decision

A module that parts of Canvas share through the import map, and that isn't
for Code Components:

- is a module of `astro-hydration`, built into a file of its own, that
  exports only what Canvas uses;
- has an import map entry named under `@drupal-canvas/astro-hydration/`, the
  name of the private package that builds it, such as
  `@drupal-canvas/astro-hydration/hydration-data`;
- isn't an external for building Code Components, so a Code Component that
  imports the package it wraps bundles its own copy;
- is imported by that specifier, which the `astro-hydration` build keeps bare
  so the import map resolves it.

A third-party package that only such a module uses is listed in
`canvas.buildOnly` in `astro-hydration`'s `package.json`, as it has no import
map entry of its own.

## Consequences

- Canvas can share a module through the import map without adding to what
  Code Components may import, and can change or remove it like any other
  internal code.
- A Code Component that imports such a specifier anyway works where Drupal
  serves the import map, but not where it doesn't, such as in a headless
  frontend. That isn't supported.
- A site can replace such an entry through the import map, as it can any
  entry, and every part of Canvas then uses its replacement.
- `canvas.buildOnly` lists packages that only such modules use, as well as
  build-time packages.
