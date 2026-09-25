import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

const dirname = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
// One React copy for Workbench and the `drupal-canvas` package it renders,
// as createWorkbenchConfig() arranges for the dev server.
const reactPackageRoot = path.dirname(require.resolve('react/package.json'));
const reactDomPackageRoot = path.dirname(
  require.resolve('react-dom/package.json'),
);

export default defineConfig({
  plugins: [react()],
  resolve: {
    dedupe: ['react', 'react-dom'],
    alias: {
      'react-dom/client': path.join(reactDomPackageRoot, 'client.js'),
      'react/jsx-runtime': path.join(reactPackageRoot, 'jsx-runtime.js'),
      'react/jsx-dev-runtime': path.join(
        reactPackageRoot,
        'jsx-dev-runtime.js',
      ),
      react: reactPackageRoot,
      'react-dom': reactDomPackageRoot,
      '@wb': path.resolve(dirname, './src'),
      'virtual:drupal-canvas/site-data': path.resolve(
        dirname,
        './src/client/test-stubs/site-data.ts',
      ),
    },
  },
  test: {
    include: ['src/**/*.test.{ts,tsx}'],
    setupFiles: ['./src/vitest-setup.ts'],
    environmentOptions: {
      jsdom: {
        url: 'http://localhost/',
      },
    },
    root: '.',
  },
});
