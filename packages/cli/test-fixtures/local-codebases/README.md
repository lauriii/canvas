# Local codebase fixtures

These miniature codebases cover distinct Canvas CLI build and validation
scenarios.

## `build-default-graph`

Integration graph for the shared build service. It combines component imports,
shared local code, third-party dependencies, built-in Canvas imports, component
CSS, global CSS, static assets, and backend metadata extraction.

## `build-custom-roots`

Custom configuration fixture. It verifies non-default `componentDir`,
`aliasBaseDir`, and `globalCssPath` values, plus named component files such as
`banner.component.yml`, `banner.tsx`, and `banner.css`.

## `imports-and-assets-supported-local-codebase`

Positive fixture for the documented imports and assets matrix. It demonstrates
supported component imports, built-in packages, third-party packages, shared
local code, shared React component modules, relative and alias static assets,
SVG URL imports, and SVG React component imports.

## `imports-and-assets-unsupported-caught-by-eslint`

Negative fixture for documented unsupported imports. It verifies validation
errors for component helper imports, relative JavaScript or TypeScript module
imports, CSS side-effect imports, font package imports, and nested component
directories.
