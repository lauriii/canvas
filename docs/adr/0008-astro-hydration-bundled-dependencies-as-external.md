# 8. Mark import map libraries as external when imported from nested dependencies

Date: 2026-01-12

Issue: <https://www.drupal.org/project/canvas/issues/3566465>

Supersedes: [ADR-0007](0007-drupal-canvas-no-external-bundling.md)

## Status

Accepted

## Context

[ADR-0007](0007-drupal-canvas-no-external-bundling.md) addressed a cache-busting
problem by bundling dependencies in `packages/drupal-canvas` using the
`noExternal` option. While this worked, it fixed the problem in the wrong place.

The `astro-hydration` build output is bundled and exposed via an import map.
Cache-busting works by appending a version query string to import map URLs
(e.g., `clsx.js?1.0.1`). The version is read from `ui/package.json` by
`\Drupal\canvas\Version::getVersion` and added to import map entries in
`\Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::renderComponent`. For
development (`0.0.0` version), Drupal core's `AssetQueryStringInterface`
provides a timestamp-based query string instead.

The problem occurs when a nested dependency (e.g., `drupal-canvas`) imports a
library that `astro-hydration` also bundles (e.g., `clsx`). By default, Rollup
creates a shared chunk with a relative import (`./clsx.js`). Relative imports
bypass the import map, so the cache-busting query string doesn't apply.

Fixing this in each nested dependency requires every package author to know
about and apply the workaround, leading to duplicated configuration and
potential code duplication.

## Decision

Fix the problem at its source in `astro-hydration`'s Rollup configuration. See
`ui/lib/astro-hydration/astro.config.mjs`.

The `external` option is configured as a function that checks whether an import
is for a library exposed via the import map. When a match is found:

- **Bundle** if imported directly from `astro-hydration/src/`
- **Bundle** if it's an internal import within the same package (e.g.,
  `swr/dist/_internal` imported by `swr/dist/index`)
- **Bundle** if imported from a build-only package (e.g., `@astrojs/preact`
  importing `preact`)
- **Bundle** if the parent is a Vite/Rollup wrapper (e.g., `?commonjs-es-import`)
- **Mark external** otherwise (imports from nested dependencies)

When marked external, Rollup generates a bare specifier import (e.g.,
`import { clsx } from 'clsx'`) instead of a relative path. The import map
intercepts bare specifiers and applies the cache-busting query string.

The list of import map libraries is derived from `astro-hydration`'s
`package.json` dependencies, excluding build-time dependencies (like `astro` and
`@astrojs/preact`) that don't appear in the import map. The exclude list is
maintained in `package.json` under `canvas.buildOnly`.

## Consequences

**Benefits:**

- Centralized fix that works for all nested dependencies
- Each library is bundled exactly once
- No workarounds needed in individual packages

**Trade-offs:**

- New build-time dependencies must be added to `canvas.buildOnly` in
  `package.json` to exclude them from import map handling
