import { defineConfig } from 'tsup';

export default defineConfig({
  entry: ['src/index.ts'],
  format: ['esm'],
  clean: true,
  sourcemap: process.env.NODE_ENV === 'development',
  splitting: false,
  treeshake: true,
  minify: false,
  external: ['vite-plugin-svgr'],
  publicDir: 'assets',
  noExternal: [
    'tailwindcss-in-browser',
    '@drupal-canvas/auth',
    '@drupal-canvas/discovery',
    '@drupal-canvas/json-schema-validation',
    '@drupal-canvas/vite-compat',
  ],
  // The bundled CommonJS validators from ajv-formats-draft2019 load punycode
  // dynamically, so the ESM CLI bundle must provide require.
  banner: {
    js: "import { createRequire as __createRequire } from 'node:module'; const require = __createRequire(import.meta.url);",
  },
  loader: {
    '.wasm': 'file',
  },
});
