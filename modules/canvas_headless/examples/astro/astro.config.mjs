// @ts-check
import node from '@astrojs/node';
import canvas from '@drupal-canvas/headless-astro/integration';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'astro/config';

// Draft preview needs per-request rendering (the session lives in
// cookies), so every page renders on demand. The canvas() integration
// injects the draft and component metadata routes, registers the CSP
// frame-ancestors middleware, and writes the component manifest at build
// time.
export default defineConfig({
  output: 'server',
  adapter: node({ mode: 'standalone' }),
  integrations: [canvas()],
  vite: {
    plugins: [tailwindcss()],
  },
});
