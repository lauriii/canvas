import { defineConfig } from 'tsdown';

export default defineConfig({
  entry: [
    'src/index.ts',
    // Backward compatibility entries for elements that were moved into drupal-canvas package.
    'src/drupal-utils.ts',
    'src/FormattedText.tsx',
    'src/jsonapi-client.ts',
    'src/jsonapi-utils.ts',
    'src/next-image-standalone.tsx',
    'src/utils.ts',
  ],
  sourcemap: process.env.NODE_ENV === 'development',
  platform: 'browser',
  external: ['react/jsx-runtime'],
  // @see docs/adr/0007-drupal-canvas-no-external-bundling.md
  // Bundle these dependencies so that when astro-hydration in Canvas imports
  // from drupal-canvas, it doesn't create separate chunks with relative imports
  // that bypass import map cache-busting. Without this, Rollup creates chunks
  // with minified export names (e.g., `export {i as c}`) that aren't stable
  // across builds. If a browser caches an old chunk with different minified
  // names, imports fail with "does not provide an export named 'c'".
  noExternal: ['clsx', 'tailwind-merge'],
  dts: true,
});
