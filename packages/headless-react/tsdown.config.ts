import { defineConfig } from 'tsdown';

export default defineConfig({
  clean: ['dist'],
  entry: {
    index: 'src/index.ts',
  },
  format: ['es'],
  // Emit .js and .d.ts (not .mjs/.d.mts) to match the repository's published
  // package convention regardless of the tsdown version's default.
  fixedExtension: false,
  // Keep each source module as its own output file. Bundling
  // draft-session.tsx's 'use client' directive together with other modules
  // drops it, breaking every consumer build (RSC bundlers then treat the
  // module as server code).
  unbundle: true,
  platform: 'node',
  dts: {
    eager: true,
  },
  outDir: 'dist',
});
