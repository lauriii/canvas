import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';

// The @drupal-canvas/headless packages are consumed as raw TypeScript
// through file: links, so the type checker follows their import chain into
// the @drupal-canvas/discovery sources, which reference the canonical
// component types in the Canvas UI package by path alias. These
// exact-match mappings (absolute, because the generated tsconfig lives in
// the build directory) make that chain resolvable. They become unnecessary
// once the packages are published compiled.
const uiTypesPath = (relative: string) =>
  fileURLToPath(new URL(`../../../../ui/src/${relative}`, import.meta.url));

const uiTypesAliases = {
  '@drupal-canvas/ui/types/CodeComponent': uiTypesPath(
    'types/CodeComponent.ts',
  ),
  '@/types/CodeComponent': uiTypesPath('types/CodeComponent.ts'),
  '@/features/code-editor/component-data/derivedPropTypes': uiTypesPath(
    'features/code-editor/component-data/derivedPropTypes.ts',
  ),
};

// The @drupal-canvas/headless-nuxt module mounts the draft and component
// metadata routes, registers the CSP frame-ancestors middleware, and
// writes the component manifest at build time. Draft preview needs
// per-request rendering (the session lives in cookies); Nuxt renders on
// demand by default.
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },
  modules: ['@drupal-canvas/headless-nuxt'],
  css: ['~/assets/css/main.css'],
  vite: {
    plugins: [tailwindcss()],
  },
  // Nuxt propagates aliases into the app, server, and shared tsconfigs'
  // paths (a user-supplied typescript.tsConfig paths map is not honored).
  // These are type-only modules, never imported at runtime, so the Vite
  // side of the alias is inert.
  alias: uiTypesAliases,
  // The node context (this config file and the modules it loads) is not
  // alias-managed, so it takes the mappings directly.
  typescript: {
    nodeTsConfig: {
      compilerOptions: {
        paths: Object.fromEntries(
          Object.entries(uiTypesAliases).map(([key, target]) => [
            key,
            [target],
          ]),
        ),
      },
    },
  },
  app: {
    head: {
      title: 'Canvas Headless example app (Nuxt)',
      htmlAttrs: { lang: 'en', class: 'h-full antialiased' },
      bodyAttrs: { class: 'flex min-h-full flex-col' },
      meta: [
        {
          name: 'description',
          content:
            'Example frontend app embedded in the Drupal Canvas editor, rendering draft content via user-bound preview tokens.',
        },
      ],
    },
  },
});
