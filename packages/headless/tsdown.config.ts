import { defineConfig } from 'tsdown';

export default defineConfig({
  clean: ['dist'],
  copy: [
    {
      from: '../preview-geometry/src/preview.css',
      to: 'dist',
      flatten: true,
    },
  ],
  entry: {
    index: 'src/index.ts',
    'client/index': 'src/client/index.ts',
    'server/index': 'src/server/index.ts',
    node: 'src/node.ts',
    'components-endpoint/index': 'src/components-endpoint/index.ts',
    'components-endpoint/handler': 'src/components-endpoint/handler.ts',
    'component-registry': 'src/component-registry.ts',
    vite: 'src/vite.ts',
  },
  format: ['es'],
  // Emit .js and .d.ts (not .mjs/.d.mts) to match the repository's published
  // package convention regardless of the tsdown version's default.
  fixedExtension: false,
  platform: 'node',
  deps: {
    // Bundle the workspace packages into the published build so consumers do
    // not need the Canvas monorepo source layout at runtime.
    alwaysBundle: [
      '@drupal-canvas/discovery',
      '@drupal-canvas/height-reader',
      '@drupal-canvas/preview-geometry',
    ],
    // Discovery's own runtime dependencies, pulled in transitively.
    onlyBundle: ['glob', 'ignore', 'js-yaml'],
  },
  dts: {
    eager: true,
    // Inline declarations for the bundled workspace packages and the type-only
    // references they hold into the unpublished UI workspace.
    resolve: [
      '@drupal-canvas/discovery',
      '@drupal-canvas/height-reader',
      '@drupal-canvas/preview-geometry',
      /^@drupal-canvas\/ui\//,
    ],
  },
  outDir: 'dist',
});
