import { defineConfig } from 'tsup';

// IIFE, same shape canvas_translate ships: the extension is a static document
// served from the module directory, so there is no module graph to rely on.
export default defineConfig({
  entry: ['index.ts'],
  format: ['iife'],
  globalName: 'canvasCodeEditorSpike',
  clean: true,
  minify: true,
  // tailwindcss-in-browser imports a Lightning CSS .wasm; copy it out rather
  // than inlining 13.6 MB as base64. The SWC wasm is copied by the build
  // script, because its glue resolves it via import.meta (empty in IIFE).
  loader: { '.wasm': 'copy' },
});
