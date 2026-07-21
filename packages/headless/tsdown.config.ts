import { defineConfig } from 'tsdown';

export default defineConfig({
  clean: ['dist'],
  entry: {
    index: 'src/index.ts',
    'client/index': 'src/client/index.ts',
    'server/index': 'src/server/index.ts',
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
    // Bundle the workspace discovery package into the published build so
    // consumers do not need the Canvas monorepo source layout at runtime.
    alwaysBundle: ['@drupal-canvas/discovery'],
    // Discovery's own runtime dependencies, pulled in transitively.
    onlyBundle: ['glob', 'ignore', 'js-yaml'],
  },
  dts: {
    // Inline declarations for the bundled discovery package and the type-only
    // references it holds into the unpublished UI workspace.
    resolve: ['@drupal-canvas/discovery', /^@drupal-canvas\/ui\//],
  },
  outDir: 'dist',
});
