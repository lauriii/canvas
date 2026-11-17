import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// Drupal maps `react` to `preact/compat` through the page's import map; the
// tests do the same so the `drupal-canvas` providers render as Preact.
export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.ts'],
    // Transformed, so the aliases below reach its imports too.
    server: { deps: { inline: ['swr'] } },
  },
  resolve: {
    alias: [
      // Drupal's import map gives Canvas's own entries; the tests use the
      // source.
      {
        find: '@drupal-canvas/astro-hydration/hydration-data',
        replacement: fileURLToPath(
          new URL('./src/lib/hydration-data.ts', import.meta.url),
        ),
      },
      { find: /^react$/, replacement: 'preact/compat' },
      { find: /^react\/jsx-runtime$/, replacement: 'preact/jsx-runtime' },
      { find: /^react-dom$/, replacement: 'preact/compat' },
      // swr's CommonJS shim would require React itself.
      {
        find: /^use-sync-external-store\/shim(\/index\.js)?$/,
        replacement: 'preact/compat',
      },
    ],
  },
});
