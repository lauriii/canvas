import { defineConfig } from 'tsdown';

export default defineConfig({
  clean: ['dist'],
  copy: [
    {
      from: '../preview-geometry/src/preview.css',
      to: 'dist',
      flatten: true,
    },
  ],
  entry: {
    index: 'src/index.ts',
    renderer: 'src/renderer.ts',
  },
  format: ['es'],
  fixedExtension: false,
  platform: 'neutral',
  deps: {
    alwaysBundle: ['@drupal-canvas/preview-geometry'],
  },
  dts: {
    eager: true,
    resolve: ['@drupal-canvas/preview-geometry'],
  },
  outDir: 'dist',
});
