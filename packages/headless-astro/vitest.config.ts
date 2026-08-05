import { getViteConfig } from 'astro/config';

const virtualComponentsId = '\0virtual:@drupal-canvas/headless/components';

export default getViteConfig({
  plugins: [
    {
      name: 'canvas-headless-astro-test-components',
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
  test: {
    include: ['src/**/*.test.ts'],
  },
});
