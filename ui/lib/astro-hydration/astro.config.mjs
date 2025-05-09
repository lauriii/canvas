import { defineConfig } from 'astro/config';
import preact from '@astrojs/preact';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// https://astro.build/config
export default defineConfig({
  // Enable Preact to support Preact JSX components.
  integrations: [preact()],
  vite: {
    resolve: {
      alias: {
        '@': path.resolve(__dirname, 'src/'),
      },
    },
    build: {
      rollupOptions: {
        output: {
          // Filename pattern for the output files
          entryFileNames: '[name].js',
          chunkFileNames: (chunkInfo) => {
            // Make sure the output chunks for dependencies have useful file
            // names so we can easily distinguish between them.
            const matches = {
              clsx: 'clsx.js',
              'class-variance-authority': 'class-variance-authority.js',
              'tailwind-merge': 'tailwind-merge.js',
              'lib/astro-hydration/src/lib/FormattedText.tsx': 'FormattedText.js',
              'lib/astro-hydration/src/lib/utils.ts': 'util.js',
            };
            return Object.entries(matches).reduce((carry, [key, value]) => {
              if (chunkInfo.facadeModuleId?.includes(`node_modules/${key}`)) {
                return value;
              }
              return carry;
            }, '[name].js');
          },
          assetFileNames: '[name][extname]',
        },
      },
    },
  },
});
