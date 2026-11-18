import { defineConfig } from 'vitest/config';

export default defineConfig({
  resolve: {
    // Use the same Angular runtime for the adapter and the standalone renderer.
    dedupe: [
      '@angular/common',
      '@angular/compiler',
      '@angular/core',
      '@angular/platform-browser',
      '@angular/router',
    ],
  },
  test: {
    include: ['tests/**/*.test.ts'],
    environment: 'node',
    server: {
      deps: {
        inline: [/@angular\//, /json-render-angular/, /headless-angular/],
      },
    },
  },
});
