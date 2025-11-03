import path from 'path';
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./tests/vitest/support/vitest.setup.js'],
    mockReset: true,
    restoreMocks: true,
    deps: {
      optimizer: {
        web: {
          enabled: true,
        },
      },
    },
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
      '@assets': path.resolve(__dirname, './assets'),
      '@experimental': path.resolve(__dirname, '../experimental'),
      '@tests': path.resolve(__dirname, './tests'),
    },
  },
});
