# 7. Bundle certain dependencies in drupal-canvas to preserve import map cache-busting

Date: 2026-01-08

Issue: <https://www.drupal.org/project/canvas/issues/3566465>

## Status

Superseded by [ADR-0008](0008-astro-hydration-bundled-dependencies-as-external.md)

## Context

The `astro-hydration` build bundles `packages/drupal-canvas` and creates
JavaScript chunks that are loaded in the browser via import maps. Import maps
include cache-busting query strings to ensure browsers and edge caches fetch
updated code after deployments.

Cache-busting works by appending a version query string to import map URLs
(e.g., `clsx.js?1.0.1`). The version is read from `ui/package.json` by
`\Drupal\canvas\Version::getVersion` and added to import map entries in
`\Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::renderComponent`. For
development (`0.0.0` version), Drupal core's `AssetQueryStringInterface`
provides a timestamp-based query string instead.

By default, tsdown (the bundler for `packages/drupal-canvas`) treats
dependencies like `clsx` and `tailwind-merge` as external. When
`astro-hydration` bundles `drupal-canvas/utils`, Rollup resolves these external
imports and creates separate chunks with relative paths (e.g.,
`import {c as o} from "./clsx.js"`).

This causes two problems:

1. **Relative paths bypass import maps.** Import maps only intercept bare
   specifiers (e.g., `clsx`) and absolute URLs, not relative paths. The
   cache-busting query strings don't apply to `./clsx.js`.

2. **Minified export names are unstable across builds.** Rollup uses minified
   names like `export {i as c}` that change between builds. If a browser caches
   an old chunk with different minified names, imports fail at runtime:

   ```
   Uncaught SyntaxError: The requested module './clsx.js' does not provide an export named 'c'
   ```

## Decision

Configure `drupal-canvas` to bundle `clsx` and `tailwind-merge` using tsdown's
`noExternal` option in `packages/drupal-canvas/tsdown.config.ts`:

```typescript
noExternal: ['clsx', 'tailwind-merge'],
```

This embeds the dependency code inline in `drupal-canvas/utils`, eliminating the
separate chunks and their problematic relative imports.

## Consequences

**Benefits:**

- Cache invalidation works correctly via import maps
- No runtime errors from stale cached chunks with mismatched export names

**Trade-offs:**

- Minor code duplication: `Stub.jsx` also imports `clsx` and `tailwind-merge`
  directly, creating separate chunks. The code exists both inlined in
  `drupal-canvas/utils` and as standalone chunks.

**Future considerations:**

When adding a new dependency to `packages/drupal-canvas`, you must add it to
`noExternal` in `tsdown.config.ts` if ALL of the following are true:

1. The dependency is used in a `drupal-canvas` entry point (e.g., `utils.ts`,
   `jsonapi-client.ts`)
2. That entry point is imported by
   `ui/lib/astro-hydration/src/components/Stub.jsx`
3. The dependency is NOT already in astro-hydration's `external` list (like
   React)

If you skip this step, a cache invalidation bug will occur: Rollup will create
separate chunks with relative imports that bypass the import map, and users will
see runtime errors after deployments when browsers serve stale cached chunks
with mismatched minified export names.
