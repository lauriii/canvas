import { defineConfig } from 'vitest/config';

const virtualComponentsId = '\0virtual:@drupal-canvas/headless/components';

export default defineConfig({
  plugins: [
    {
      name: 'canvas-headless-nuxt-test-components',
      resolveId(id) {
        return id === 'virtual:@drupal-canvas/headless/components'
          ? virtualComponentsId
          : null;
      },
      load(id) {
        return id === virtualComponentsId ? 'export default {}' : null;
      },
    },
  ],
});
