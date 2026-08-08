import { defineConfig } from 'tsup';

export default defineConfig({
  entry: ['src/index.ts', 'src/internals.ts'],
  format: ['esm'],
  dts: {
    entry: 'src/internals.ts',
    // tsup's declaration build does not pick up the `paths` the root tsconfig
    // declares, so workspace-internal imports are restated here.
    compilerOptions: {
      paths: {
        '@drupal-canvas/ui/*': ['../../ui/src/*'],
        '@/*': ['../../ui/src/*'],
        '@assets/*': ['../../ui/assets/*'],
        '@drupal-canvas/discovery': ['../discovery/src/index.ts'],
        '@drupal-canvas/vite-compat': ['../vite-compat/src/index.ts'],
        '@drupal-canvas/types': ['../types/src'],
      },
    },
  },
  clean: true,
  sourcemap: process.env.NODE_ENV === 'development',
  // The two entries share most of their code; splitting keeps one copy.
  splitting: true,
  treeshake: true,
  minify: false,
  external: ['vite-plugin-svgr'],
  publicDir: 'assets',
  noExternal: [
    'tailwindcss-in-browser',
    '@drupal-canvas/auth',
    '@drupal-canvas/discovery',
    '@drupal-canvas/vite-compat',
  ],
  loader: {
    '.wasm': 'file',
  },
});
