import { defineConfig } from 'tsup';

export default defineConfig({
  entry: ['src/index.ts'],
  format: ['esm'],
  clean: true,
  sourcemap: true,
  splitting: false,
  treeshake: true,
  minify: false,
  noExternal: ['tailwindcss-in-browser'],
  loader: {
    '.wasm': 'file',
  },
});
