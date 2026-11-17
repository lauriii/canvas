// Encodes and decodes an island's hydration data. Canvas SSR encodes it and
// the island client renderer decodes it, both through the import map entry
// for `@drupal-canvas/astro-hydration/hydration-data`, so they use the same
// copy of devalue. The entry is Canvas's own, not one for Code Components.
// @see ./canvas-client.ts
// @see \Drupal\canvas\GlobalImports::getImportMap()
export { DevalueError, parse, stringify } from 'devalue';
