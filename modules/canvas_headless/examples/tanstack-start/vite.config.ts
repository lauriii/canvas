import { defineConfig } from 'vite'
import { devtools } from '@tanstack/devtools-vite'
import { canvas } from '@drupal-canvas/headless-tanstack-start/vite'

import { tanstackStart } from '@tanstack/react-start/plugin/vite'

import viteReact from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// The canvas() plugin compiles the raw-TypeScript SDK packages into the
// SSR build, bridges the SDK's .env keys into process.env, and writes the
// component manifest at build time, inlined into the server bundle.
const config = defineConfig({
  resolve: { tsconfigPaths: true },
  plugins: [devtools(), canvas(), tailwindcss(), tanstackStart(), viteReact()],
})

export default config
