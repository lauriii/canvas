import { defineConfig } from 'tsup';

// ESM, NOT the IIFE recipe the canvas-extension skill prescribes.
// Verified on a live site: with `format: 'iife'` this bundle throws
// `Dynamic require of "./lightningcss_node-*.wasm?url" is not supported`
// at module scope and never boots, because `tailwindcss-in-browser` imports its
// Lightning CSS wasm with a Vite-style `?url` suffix that an IIFE cannot express.
// ESM also restores `import.meta.url`, so the SWC wasm needs no baseURI hack.
export default defineConfig({
  entry: ['index.ts'],
  format: ['esm'],
  // tsup leaves `dependencies` external for ESM; a static extension document has
  // no resolver, so everything must be bundled.
  noExternal: [/.*/],
  clean: true,
  minify: true,
  // tailwindcss-in-browser imports a Lightning CSS .wasm; copy it out rather
  // than inlining 13.6 MB as base64. The SWC wasm is copied by the build
  // script, because its glue resolves it via import.meta (empty in IIFE).
  loader: { '.wasm': 'copy' },
});
