import { resolve } from 'path';
import { loadEnv } from 'vite';

import type { Plugin } from 'vite';

interface Options {
  componentDir?: string;
  siteUrl?: string;
  jsonapiPrefix?: string;
}

export default function (options: Options = {}): Plugin[] {
  let env: Record<string, string>;

  return [
    {
      name: 'drupal-canvas',

      // Configure Drupal Canvas specific alias resolving.
      config(config, { mode }) {
        const root = config.root ?? process.cwd();
        env = loadEnv(mode, process.cwd(), 'CANVAS_');
        const componentsDir =
          options.componentDir ?? env.CANVAS_COMPONENT_DIR ?? './components';
        return {
          ...config,
          resolve: {
            alias: {
              '@/components': resolve(root, componentsDir),
            },
          },
        };
      },

      // Inject drupalSettings.canvasData with options needed for JsonApiClient configuration.
      transformIndexHtml(html) {
        const canvasData = {
          baseUrl: options.siteUrl ?? env.CANVAS_SITE_URL,
          jsonapiSettings: {
            apiPrefix: options.jsonapiPrefix ?? env.CANVAS_JSONAPI_PREFIX,
          },
        };
        const scriptContent = `window.drupalSettings = { canvasData: { v0: ${JSON.stringify(canvasData)} } };`;
        return {
          html,
          tags: [
            {
              tag: 'script',
              attrs: { type: 'text/javascript' },
              children: scriptContent,
              injectTo: 'head',
            },
          ],
        };
      },
    },
  ];
}
