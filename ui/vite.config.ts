import fs from 'fs';
import path from 'path';
import { defineConfig, loadEnv } from 'vite';
import svgr from 'vite-plugin-svgr';
import react from '@vitejs/plugin-react';

import { verifyBundleIsScannable } from './lib/locale-extract.js';

// https://vitejs.dev/config/

export default defineConfig(({ command, mode }) => {
  // https://vitejs.dev/config/#using-environment-variables-in-config
  const env = loadEnv(mode, process.cwd(), '');
  return {
    define: {
      __APP_ENV__: JSON.stringify(env.APP_ENV),
    },
    plugins: [
      react(),
      svgr(),
      {
        // Copy the code editor preview script to the dist directory when the
        // app is built.
        // @see ui/lib/code-editor-preview.js
        name: 'copy-code-editor-preview',
        writeBundle() {
          fs.copyFileSync(
            'lib/code-editor-preview.js',
            'dist/assets/code-editor-preview.js',
          );
        },
      },
      {
        // Fail the build when a translatable string in the source cannot be
        // found in the bundle Drupal actually scans. Without this the editor
        // ships looking translatable while offering translators nothing.
        // @see ui/lib/locale-extract.js
        name: 'verify-translatable-strings',
        writeBundle(options, bundle) {
          const outDir = options.dir ?? 'dist/assets';
          // Only the entry bundle, because `canvas.libraries.yml` attaches only
          // that file and Drupal scans what is attached. A string that ended up
          // in a lazily loaded chunk would never be offered for translation, so
          // checking the chunks too would hide the problem rather than find it.
          // @see canvas.libraries.yml
          const entries = Object.values(bundle)
            .filter((chunk) => chunk.type === 'chunk' && chunk.isEntry)
            .map((chunk) => path.join(outDir, chunk.fileName));
          const { problems, strings, callSites } = verifyBundleIsScannable(
            path.resolve(__dirname, 'src'),
            entries,
          );
          if (problems.length) {
            throw new Error(
              `Translatable strings would not reach Drupal:\n- ${problems.join('\n- ')}`,
            );
          }
          this.info(
            `Verified ${strings.length} translatable string(s) from ${callSites} call site(s) are discoverable by Drupal's locale scanner.`,
          );
        },
      },
    ],
    server: {
      // open: true,
      fs: {
        // Component tests using this vite config do not have this as a parent
        // directory. We disable strict so they can be served by Vite.
        strict: false,
      },
      origin: env.VITE_SERVER_ORIGIN || 'http://localhost:5173', // Origin for the generated asset URLs.
      headers: {
        // Allow the dev server to be accessed from any origin (unless it's
        // restricted by the VITE_SERVER_CORS_ALLOW_ORIGIN environment
        // variable), because development setups may vary.
        // These settings are insecure for production use.
        'Access-Control-Allow-Origin': env.VITE_SERVER_CORS_ALLOW_ORIGIN || '*',
        'Access-Control-Allow-Methods': 'GET',
        'Access-Control-Allow-Headers': 'Content-Type, Authorization',
        'Referrer-Policy': '*',
      },
    },
    build: {
      reportCompressedSize: false,
      rollupOptions: {
        // external: ['react', 'react-dom', "redux", "@reduxjs/toolkit"],
        output: {
          entryFileNames: `assets/[name].js`,
          chunkFileNames: `assets/[name].js`,
          assetFileNames: `assets/[name].[ext]`,
        },
      },
    },
    resolve: {
      alias: {
        '@': path.resolve(__dirname, './src'),
        '@assets': path.resolve(__dirname, './assets'),
        '@experimental': path.resolve(__dirname, '../experimental'),
        '@tests': path.resolve(__dirname, './tests'),
        '@drupal-canvas/astro-hydration': path.resolve(
          __dirname,
          '../packages/astro-hydration',
        ),
      },
    },
    optimizeDeps: {
      // These libraries need to be excluded from Vite's dependency optimization until
      // https://github.com/vitejs/vite/issues/8427 is fixed.
      exclude: ['@swc/wasm-web', 'tailwindcss-in-browser'],
    },
  };
});
