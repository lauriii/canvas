import { resolve } from 'path';
import { loadEnv } from 'vite';
import { getTokenEntry } from '@drupal-canvas/auth';

import type { Plugin } from 'vite';

interface Options {
  componentDir?: string;
  siteUrl?: string;
  jsonapiPrefix?: string;
}

function readAccessToken(siteUrl: string): string | null {
  if (process.env.CANVAS_ACCESS_TOKEN) {
    return process.env.CANVAS_ACCESS_TOKEN;
  }
  const entry = getTokenEntry(siteUrl);
  if (
    entry?.accessToken &&
    (!entry.expiresAt || entry.expiresAt > Date.now())
  ) {
    return entry.accessToken;
  }
  return null;
}

function prependBaseUrl(url: unknown, base: string): unknown {
  if (typeof url !== 'string' || !url.startsWith('/')) return url;
  return `${base}${url}`;
}

export default function (options: Options = {}): Plugin[] {
  let env: Record<string, string>;
  let canvasApiData: Record<string, unknown> | null = null;

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

      // Fetch live site data from the Canvas HTTP API so that getSiteData()
      // works in Workbench without a full Drupal page render.
      async buildStart() {
        const siteUrl = options.siteUrl ?? env.CANVAS_SITE_URL;
        if (!siteUrl) {
          return;
        }
        const token = readAccessToken(siteUrl);
        if (!token) {
          console.warn(
            '[drupal-canvas] No access token found — run `canvas login` to enable live site data in Workbench. Falling back to static config.',
          );
          return;
        }
        try {
          const url = `${siteUrl.replace(/\/+$/, '')}/canvas/api/v0/site-data`;
          const response = await fetch(url, {
            headers: { Authorization: `Bearer ${token}` },
          });
          if (response.ok) {
            canvasApiData = (await response.json()) as Record<string, unknown>;
          } else {
            console.warn(
              `[drupal-canvas] Canvas API returned HTTP ${response.status} — falling back to static config.`,
            );
          }
        } catch (e) {
          console.warn(
            `[drupal-canvas] Failed to fetch live site data — falling back to static config. ${e instanceof Error ? e.message : String(e)}`,
          );
        }
      },

      // Inject drupalSettings.canvasData with options needed for JsonApiClient configuration.
      transformIndexHtml(html) {
        const effectiveSiteUrl = options.siteUrl ?? env.CANVAS_SITE_URL;
        const effectiveJsonapiPrefix =
          options.jsonapiPrefix ?? env.CANVAS_JSONAPI_PREFIX;
        const v0: Record<string, unknown> = {
          baseUrl: effectiveSiteUrl,
          // Only use the static jsonapiPrefix when no API data is available,
          // because the API response already includes jsonapiSettings.
          ...(effectiveJsonapiPrefix && !canvasApiData
            ? { jsonapiSettings: { apiPrefix: effectiveJsonapiPrefix } }
            : {}),
          // API response overrides static values and supplies site-level
          // canvasData.v0 fields (branding, themeAssets, etc.).
          // Relative theme asset paths are resolved to absolute URLs using baseUrl.
          ...(() => {
            if (!canvasApiData) return {};
            const base = (
              (canvasApiData.baseUrl as string) ??
              effectiveSiteUrl ??
              ''
            ).replace(/\/+$/, '');
            const themeAssets = canvasApiData.themeAssets as
              | Record<string, Record<string, unknown>>
              | undefined;
            if (!themeAssets) return canvasApiData;
            return {
              ...canvasApiData,
              themeAssets: {
                ...themeAssets,
                ...(themeAssets.logo && {
                  logo: {
                    ...themeAssets.logo,
                    url: prependBaseUrl(themeAssets.logo.url, base),
                  },
                }),
                ...(themeAssets.favicon && {
                  favicon: {
                    ...themeAssets.favicon,
                    url: prependBaseUrl(themeAssets.favicon.url, base),
                  },
                }),
              },
            };
          })(),
        };
        const scriptContent = `window.drupalSettings = { canvasData: { v0: ${JSON.stringify(v0)} } };`;
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
