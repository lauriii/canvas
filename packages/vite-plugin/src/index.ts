import { resolve } from 'path';
import { loadEnv } from 'vite';

import type { Plugin } from 'vite';

interface Options {
  componentDir?: string;
  siteUrl?: string;
  jsonapiPrefix?: string;
  /** Fetch implementation, injectable for tests. */
  fetch?: typeof fetch;
}

/**
 * The virtual module exporting the site data (`drupalSettings.canvasData.v0`
 * shape) the plugin loaded from the Canvas site-data endpoint. Workbench and
 * generated previews import it to supply the `drupal-canvas` context provider
 * directly, independent of the `drupalSettings` compatibility mechanism.
 */
export const CANVAS_SITE_DATA_MODULE_ID = 'virtual:drupal-canvas/site-data';

const RESOLVED_CANVAS_SITE_DATA_MODULE_ID = `\0${CANVAS_SITE_DATA_MODULE_ID}`;

/** The site-level data the plugin exposes. */
export interface CanvasSiteData {
  baseUrl?: string;
  jsonapiSettings?: { apiPrefix: string } | null;
  branding?: { homeUrl: string; siteName: string; siteSlogan: string };
  themeAssets?: {
    logo: { url: string };
    favicon: { url: string; mimeType: string };
  };
  [key: string]: unknown;
}

/** The API other build tooling can read from the plugin instance. */
export interface CanvasVitePluginApi {
  /** The site data resolved so far; `buildStart` completes it. */
  getCanvasSiteData(): CanvasSiteData;
}

function prependBaseUrl(url: unknown, base: string): unknown {
  if (typeof url !== 'string' || !url.startsWith('/')) return url;
  return `${base}${url}`;
}

/**
 * Builds the `canvasData.v0` payload from static configuration and the
 * site-data response. The API response overrides static values and supplies
 * the site-level fields (branding, theme assets, JSON:API settings); relative
 * theme asset paths become absolute URLs on the site.
 */
function buildCanvasSiteData(
  effectiveSiteUrl: string | undefined,
  effectiveJsonapiPrefix: string | undefined,
  canvasApiData: Record<string, unknown> | null,
): CanvasSiteData {
  return {
    baseUrl: effectiveSiteUrl,
    // Only use the static jsonapiPrefix when no API data is available,
    // because the API response already includes jsonapiSettings.
    ...(effectiveJsonapiPrefix && !canvasApiData
      ? { jsonapiSettings: { apiPrefix: effectiveJsonapiPrefix } }
      : {}),
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
  } as CanvasSiteData;
}

export default function (options: Options = {}): Plugin[] {
  let env: Record<string, string> = {};
  let canvasApiData: Record<string, unknown> | null = null;
  const fetchImpl = options.fetch ?? fetch;

  const getCanvasSiteData = (): CanvasSiteData =>
    buildCanvasSiteData(
      options.siteUrl ?? env.CANVAS_SITE_URL,
      options.jsonapiPrefix ?? env.CANVAS_JSONAPI_PREFIX,
      canvasApiData,
    );

  const plugin: Plugin & { api: CanvasVitePluginApi } = {
    name: 'drupal-canvas',

    api: { getCanvasSiteData },

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

    // Fetch live site data once from the Canvas HTTP API so that site
    // context and getSiteData() work in Workbench without a full Drupal page
    // render. This public metadata request must not carry user credentials.
    async buildStart() {
      const siteUrl = options.siteUrl ?? env.CANVAS_SITE_URL;
      if (!siteUrl) {
        return;
      }
      try {
        const url = `${siteUrl.replace(/\/+$/, '')}/canvas/api/v0/site-data`;
        const response = await fetchImpl(url, {
          credentials: 'omit',
          headers: { Accept: 'application/json' },
        });
        if (response.ok) {
          // The site-data endpoint also advertises tooling capabilities;
          // those are not part of `canvasData.v0`.
          // eslint-disable-next-line @typescript-eslint/no-unused-vars
          const { capabilities, ...siteData } =
            (await response.json()) as Record<string, unknown>;
          canvasApiData = siteData;
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

    resolveId(id) {
      return id === CANVAS_SITE_DATA_MODULE_ID
        ? RESOLVED_CANVAS_SITE_DATA_MODULE_ID
        : undefined;
    },

    load(id) {
      if (id !== RESOLVED_CANVAS_SITE_DATA_MODULE_ID) {
        return undefined;
      }
      return `export default ${JSON.stringify(getCanvasSiteData())};`;
    },

    // Inject drupalSettings.canvasData for legacy consumers: getSiteData(),
    // getPageData(), and the legacy JsonApiClient constructor.
    transformIndexHtml(html) {
      const scriptContent = `window.drupalSettings = { canvasData: { v0: ${JSON.stringify(getCanvasSiteData())} } };`;
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
  };

  return [plugin];
}
