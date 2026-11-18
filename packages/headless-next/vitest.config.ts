import { defineConfig } from 'vitest/config';

const generatedComponentsId =
  '\0@drupal-canvas/headless-next-generated-components';

export default defineConfig({
  resolve: {
    dedupe: ['react', 'react-dom'],
  },
  plugins: [
    {
      name: 'canvas-headless-next-test-components',
      resolveId(id) {
        return id === '@drupal-canvas/headless-next-generated-components'
          ? generatedComponentsId
          : null;
      },
      load(id) {
        if (id !== generatedComponentsId) {
          return null;
        }
        return `
          import { createElement } from 'react';

          function ServerCard({ children, title }) {
            return createElement(
              'article',
              null,
              createElement('h2', null, title),
              children,
            );
          }

          export default { 'server-card': ServerCard };
        `;
      },
    },
  ],
});
