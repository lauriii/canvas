import { defineConfig } from 'vitest/config';

// Drupal maps `react` to `preact/compat` through the page's import map; the
// tests do the same so the `drupal-canvas` providers render as Preact.
export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.ts'],
  },
  resolve: {
    alias: [
      { find: /^react$/, replacement: 'preact/compat' },
      { find: /^react\/jsx-runtime$/, replacement: 'preact/jsx-runtime' },
      { find: /^react-dom$/, replacement: 'preact/compat' },
    ],
  },
});
