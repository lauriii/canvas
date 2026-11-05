import { defineConfig } from 'tsdown';

export default defineConfig({
  entry: [
    'src/index.ts',
    'src/react.ts',
    'src/json-render-utils.tsx',
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
  deps: {
    neverBundle: ['react', 'react/jsx-runtime', '@drupal-canvas/discovery'],
  },
  dts: true,
  // Let an entry that other entries import (e.g. jsonapi-client) keep its own
  // code instead of being split into a hashed shared chunk. The entry may
  // then export extra names.
  inputOptions: { preserveEntrySignatures: 'allow-extension' },
});
