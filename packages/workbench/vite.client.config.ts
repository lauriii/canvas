import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

const configPath = fileURLToPath(import.meta.url);
const packageRoot = path.dirname(configPath);
const clientRoot = path.resolve(packageRoot, 'src/client');

export default defineConfig({
  root: clientRoot,
  build: {
    outDir: path.resolve(packageRoot, 'dist/client'),
    rollupOptions: {
      external: [
        'react',
        'react-dom',
        'react/jsx-runtime',
        'react/jsx-dev-runtime',
        'react-router',
      ],
    },
  },
  plugins: [react(), tailwindcss()] as any,
  resolve: {
    alias: {
      '@wb': path.resolve(packageRoot, 'src'),
    },
  },
});
