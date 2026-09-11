import { defineConfig } from 'vite';

/**
 * Vite, NOT tsup.
 *
 * Verified on a live site that tsup cannot build this at all, in either format:
 * - `iife` throws `Dynamic require of "./lightningcss_node-*.wasm?url" is not
 *   supported` at module scope, so the app never boots.
 * - `esm` leaves `"./lightningcss_node-*.wasm?url"` as a static import of a
 *   non-JS asset, which the browser refuses ("Failed to fetch dynamically
 *   imported module").
 *
 * `?url` is a Vite convention: `tailwindcss-in-browser` expects the bundler to
 * replace that import with a string URL and emit the file as an asset. Only Vite
 * (which is what Canvas's own ui/ build uses) does that. This is a real
 * constraint on the code editor module's toolchain, not a spike detail.
 */
export default defineConfig({
  build: {
    target: 'esnext',
    outDir: 'dist',
    emptyOutDir: true,
    // Never inline the multi-MB wasm as base64.
    assetsInlineLimit: 0,
    lib: {
      entry: 'index.ts',
      formats: ['es'],
      fileName: () => 'index.js',
    },
  },
});
