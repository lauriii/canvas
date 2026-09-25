/**
 * @file
 * The page and site context Workbench previews supply to Code Components
 * through the `drupal-canvas` context providers, in both the interactive
 * preview and generated preview entries.
 *
 * Workbench previews have no Drupal page, so page context carries the page
 * defaults; site context comes from the site data the Vite integration loaded
 * once, with the same fallbacks the legacy `getSiteData()` uses.
 */

import type { CanvasSiteData } from '@drupal-canvas/vite-plugin';
import type { CanvasContext, JsonApiRuntimeConfig } from 'drupal-canvas';

/** The page context of every Workbench preview. */
export const WORKBENCH_PAGE_CONTEXT: NonNullable<CanvasContext['page']> = {
  pageTitle: '',
  breadcrumbs: [],
  mainEntity: null,
};

const EMPTY_THEME_ASSETS = {
  logo: { url: '' },
  favicon: { url: '', mimeType: '' },
};

/**
 * Builds the Workbench context from the site data the Vite integration loaded.
 *
 * @param siteData
 *   The site data, or `null` when none is configured.
 * @param fallbackBaseUrl
 *   The base URL used when the site data carries none (the preview origin).
 */
export function createWorkbenchContext(
  siteData: CanvasSiteData | null | undefined,
  fallbackBaseUrl: string,
): CanvasContext {
  return {
    page: { ...WORKBENCH_PAGE_CONTEXT },
    site: {
      branding: siteData?.branding ?? {
        homeUrl: '',
        siteName: '',
        siteSlogan: '',
      },
      baseUrl: siteData?.baseUrl || fallbackBaseUrl,
      themeAssets: siteData?.themeAssets ?? EMPTY_THEME_ASSETS,
    },
  };
}

/**
 * Builds the JSON:API runtime configuration for Workbench previews, or `null`
 * when the site reports that JSON:API is not installed.
 */
export function createWorkbenchJsonApiConfig(
  siteData: CanvasSiteData | null | undefined,
  fallbackBaseUrl: string,
): JsonApiRuntimeConfig | null {
  if (siteData?.jsonapiSettings === null) {
    return null;
  }
  return {
    baseUrl: siteData?.baseUrl || fallbackBaseUrl,
    ...(siteData?.jsonapiSettings?.apiPrefix && {
      apiPrefix: siteData.jsonapiSettings.apiPrefix,
    }),
    resourceVersion: null,
    preview: false,
  };
}
