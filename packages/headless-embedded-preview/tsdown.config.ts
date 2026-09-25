import { defineConfig } from 'tsdown';

export default defineConfig({
  clean: ['dist'],
  copy: [
    {
      from: 'src/canvas-headless-preview.css',
      to: 'dist',
      flatten: true,
    },
  ],
  deps: {
    alwaysBundle: [/^@drupal-canvas\//],
  },
  dts: false,
  entry: 'src/canvas-headless-preview.ts',
  format: ['iife'],
  minify: process.env.NODE_ENV === 'production',
  outDir: 'dist',
  outputOptions: {
    entryFileNames: '[name].js',
  },
  platform: 'browser',
  target: 'es2022',
});
