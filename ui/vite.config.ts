import { loadEnv, defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import svgr from 'vite-plugin-svgr';
import path from 'path';
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
    ],
    server: {
      // open: true,
      fs: {
        // Component tests using this vite config do not have this as a parent
        // directory. We disable strict so they can be served by Vite.
        strict: false,
      },
      origin: env.VITE_SERVER_ORIGIN || 'http://localhost:5173', // Origin for the generated asset URLs.
    },
    test: {
      globals: true,
      environment: 'jsdom',
      setupFiles: 'src/setupTests',
      mockReset: true,
    },
    build: {
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
        '@': path.resolve(__dirname, 'src/'),
        '@assets': path.resolve(__dirname, 'assets/'),
      },
    },
  };
});
