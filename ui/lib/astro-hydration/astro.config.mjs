import { defineConfig } from 'astro/config';
import preact from '@astrojs/preact';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const require = createRequire(import.meta.url);
const pkg = require('./package.json');

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
              'lib/astro-hydration/src/lib/jsx-runtime-default.js': 'jsx-runtime-default.js',
              clsx: 'clsx.js',
              'class-variance-authority': 'class-variance-authority.js',
              'tailwind-merge': 'tailwind-merge.js',
              'lib/astro-hydration/src/lib/jsonapi-params.ts': 'jsonapi-params.js',
              'lib/astro-hydration/src/lib/swr.ts': 'swr.js',
              'drupal-canvas': 'drupal-canvas.js',
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
        // @see src/features/code-editor/Preview.tsx
        // @see src/Plugin/Canvas/ComponentSource/JsComponent.php
        external: (id, parent) => {
          // Mark React external so Astro's bundler doesn't bundle it. This way
          // if a module (e.g., lib/astro-hydration/src/lib/swr.ts) imports
          // React, our import maps will handle the module resolution, which
          // will take care of aliasing to `preact/compat`. This ensures that
          // imports in the code of code components as well as in bundled
          // packages can be mapped to the same module.
          // (An alternative would be to use the `compat` option of the
          // @astrojs/preact plugin, but it doesn't produce a bundle that can
          // work in both code components and bundled packages.)
          if (['react', 'react-dom', 'react-dom/client', 'react/jsx-runtime'].includes(id)) {
            return true;
          }

          // @see docs/adr/0008-astro-hydration-bundled-dependencies-as-external.md
          // Libraries in the import map need special handling when imported from
          // nested dependencies. Without this, Rollup creates shared chunks with
          // relative imports (e.g., ./clsx.js) that bypass import maps, breaking
          // cache busting. Marking them external forces bare specifier imports
          // that the import map intercepts.
          const buildOnly = pkg.canvas?.buildOnly ?? [];
          const importMapLibraries = Object.keys(pkg.dependencies)
            .filter(dep => !buildOnly.includes(dep));
          
          // Check if id matches an import map library. Handle:
          // - Bare specifiers: "clsx", "preact"
          // - Subpath imports: "preact/hooks", "drupal-canvas/utils"
          // - Full paths: ".../node_modules/clsx/dist/clsx.mjs"
          const matchedLibrary = importMapLibraries.find(lib => 
            id === lib || 
            id.startsWith(`${lib}/`) ||
            id.includes(`/node_modules/${lib}/`)
          );
          
          if (matchedLibrary) {
            // Bundle if imported directly from astro-hydration source.
            if (parent?.includes(path.resolve(__dirname, 'src/'))) {
              return false;
            }
            // Bundle if it's an internal import within the same package (e.g.,
            // swr/dist/_internal imported by swr/dist/index).
            if (parent?.includes(`/node_modules/${matchedLibrary}/`)) {
              return false;
            }
            // Bundle if imported from a build-only package (e.g., @astrojs/preact
            // importing preact).
            if (buildOnly.some(pkg => parent?.includes(`/node_modules/${pkg}/`))) {
              return false;
            }
            // Bundle if parent is a Vite/Rollup wrapper (e.g., ?commonjs-es-import).
            if (parent?.includes('?')) {
              return false;
            }
            // Mark as external so it uses the import map.
            return true;
          }

          return false;
        }
      },
    },
  },
});
