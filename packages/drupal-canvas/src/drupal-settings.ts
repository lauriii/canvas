/**
 * @file
 * Builds the context API's values from Drupal's `drupalSettings.canvasData.v0`
 * payload. Used by the Drupal island renderer, the code editor preview, and
 * Workbench; Code Components never read `drupalSettings` themselves.
 *
 * Drupal attaches the settings selectively, based on the APIs a component
 * imports. A key that is absent means no component on the page asked for it,
 * so the corresponding context value is `null`; a key that is present with an
 * empty value is valid data.
 */

import type { CanvasContext } from './context.js';
import type { JsonApiRuntimeConfig } from './jsonapi-client.js';

/** The subset of `drupalSettings.canvasData.v0` the context API reads. */
export interface CanvasDataV0 {
  pageTitle?: string | null;
  breadcrumbs?: CanvasContext['page'] extends infer P
    ? P extends { breadcrumbs: infer B }
      ? B | null
      : never
    : never;
  mainEntity?: NonNullable<CanvasContext['page']>['mainEntity'];
  branding?: NonNullable<CanvasContext['site']>['branding'] | null;
  baseUrl?: string | null;
  themeAssets?: NonNullable<CanvasContext['site']>['themeAssets'] | null;
  jsonapiSettings?: { apiPrefix?: string } | null;
}

interface DrupalSettingsLike {
  canvasData?: { v0?: CanvasDataV0 | null } | null;
}

/** Reads `drupalSettings.canvasData.v0` from a settings object or the global. */
export function readCanvasDataV0(
  settings: DrupalSettingsLike | undefined = (
    globalThis as { drupalSettings?: DrupalSettingsLike }
  ).drupalSettings,
): CanvasDataV0 | null {
  const v0 = settings?.canvasData?.v0;
  return v0 && typeof v0 === 'object' ? v0 : null;
}

const EMPTY_THEME_ASSETS = {
  logo: { url: '' },
  favicon: { url: '', mimeType: '' },
};

/**
 * Builds the page and site context from `drupalSettings.canvasData.v0`.
 *
 * Page context is available when Drupal attached any page-level key
 * (`pageTitle`, `breadcrumbs`, or `mainEntity`); site context when it attached
 * `branding`. Missing keys inside an available group take the same defaults
 * as the legacy getters.
 */
export function drupalSettingsToCanvasContext(
  v0: CanvasDataV0 | null | undefined,
): CanvasContext {
  if (!v0) {
    return { page: null, site: null };
  }
  const hasPage =
    'pageTitle' in v0 || 'breadcrumbs' in v0 || 'mainEntity' in v0;
  const hasSite = 'branding' in v0;
  return {
    page: hasPage
      ? {
          pageTitle: v0.pageTitle || '',
          breadcrumbs: v0.breadcrumbs || [],
          mainEntity: v0.mainEntity || null,
        }
      : null,
    site: hasSite
      ? {
          branding: v0.branding || {
            homeUrl: '',
            siteName: '',
            siteSlogan: '',
          },
          baseUrl: v0.baseUrl || '/',
          themeAssets: v0.themeAssets || EMPTY_THEME_ASSETS,
        }
      : null,
  };
}

/**
 * Builds the JSON:API runtime configuration for a Drupal-rendered page from
 * `drupalSettings.canvasData.v0`, or `null` when the settings do not carry a
 * base URL or JSON:API is not installed. Previews read working copies through
 * the editor's Drupal session.
 */
export function drupalSettingsToJsonApiRuntimeConfig(
  v0: CanvasDataV0 | null | undefined,
  options: { preview?: boolean } = {},
): JsonApiRuntimeConfig | null {
  if (!v0 || typeof v0.baseUrl !== 'string' || v0.baseUrl === '') {
    return null;
  }
  if (v0.jsonapiSettings === null) {
    return null;
  }
  const preview = options.preview ?? false;
  return {
    baseUrl: v0.baseUrl,
    ...(v0.jsonapiSettings?.apiPrefix && {
      apiPrefix: v0.jsonapiSettings.apiPrefix,
    }),
    resourceVersion: preview ? 'rel:working-copy' : null,
    preview,
  };
}
