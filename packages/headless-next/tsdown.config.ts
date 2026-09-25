import { defineConfig } from 'tsdown';

export default defineConfig({
  clean: ['dist'],
  entry: {
    index: 'src/index.ts',
    middleware: 'src/middleware.ts',
    'client/index': 'src/client/index.ts',
    'config/index': 'src/config/index.ts',
    'canvas-component-tree': 'src/canvas-component-tree.tsx',
    'canvas-runtime': 'src/canvas-runtime.tsx',
    'component-preview-page': 'src/component-preview-page.tsx',
  },
  format: ['es'],
  // Emit .js and .d.ts (not .mjs/.d.mts) to match the repository's published
  // package convention regardless of the tsdown version's default.
  fixedExtension: false,
  // Keep each source module as its own output file. Bundling
  // client/draft-session.tsx's 'use client' directive together with other
  // modules drops it, breaking every consumer build (RSC bundlers then
  // treat the module as server code).
  unbundle: true,
  platform: 'node',
  deps: {
    // Keep dependency subpath specifiers exactly as written. Resolving them
    // appends '.js', which for a dependency without an 'exports' map (such as
    // 'next') yields a literal file path instead of a package specifier:
    // 'next/navigation.js' bypasses Next's server/client aliasing and breaks
    // every consumer Turbopack build.
    // @todo Remove once the repository upgrades to tsdown 0.23+, where `false`
    //    is the default: https://github.com/rolldown/tsdown/issues/888
    resolveDepSubpath: false,
    neverBundle: [
      // Resolved by the consuming app's own bundler config (the alias
      // withCanvas() installs into webpack/turbopack), never by this
      // package's build. There is no real module behind this specifier
      // until an app supplies one.
      '@drupal-canvas/headless-next-generated-components',
    ],
  },
  dts: {
    eager: true,
  },
  outDir: 'dist',
});
