import { createRequire } from 'node:module';
import path from 'node:path';
import { defineConfig } from 'vitest/config';

const require = createRequire(import.meta.url);
// One React copy for this package and the `drupal-canvas` package whose
// providers the renderer mounts, as an application's single React install
// arranges.
const reactRoot = path.dirname(require.resolve('react/package.json'));
const reactDomRoot = path.dirname(require.resolve('react-dom/package.json'));

export default defineConfig({
  test: {
    server: {
      // Installed dependencies are otherwise loaded by Node directly; SWR
      // (used by the server-rendering tests) must import the aliased React
      // copy the renderer uses.
      deps: { inline: [/\/node_modules\/swr\//] },
    },
  },
  resolve: {
    dedupe: ['react', 'react-dom'],
    alias: [
      { find: /^react$/, replacement: reactRoot },
      { find: /^react\/(.*)$/, replacement: `${reactRoot}/$1` },
      { find: /^react-dom$/, replacement: reactDomRoot },
      { find: /^react-dom\/(.*)$/, replacement: `${reactDomRoot}/$1` },
      {
        find: /^use-sync-external-store\/shim(\/index\.js)?$/,
        replacement: path.resolve(
          import.meta.dirname,
          'test/use-sync-external-store-shim.ts',
        ),
      },
    ],
  },
});
