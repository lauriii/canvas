import { defineConfig } from 'tsdown';

export default defineConfig({
  entry: [
    'src/index.ts',
    // Backward compatibility entries for elements that were moved into drupal-canvas package.
    'src/drupal-utils.ts',
    'src/FormattedText.tsx',
    'src/jsonapi-client.ts',
    'src/jsonapi-utils.ts',
    'src/next-image-standalone.tsx',
    'src/utils.ts',
  ],
  sourcemap: process.env.NODE_ENV === 'development',
  platform: 'browser',
  external: ['react/jsx-runtime'],
  dts: true,
});
