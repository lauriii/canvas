import { describe, expect, it } from 'vitest';

import {
  drupalSettingsToCanvasContext,
  drupalSettingsToJsonApiRuntimeConfig,
  readCanvasDataV0,
} from './drupal-settings';

describe('drupalSettings to context', () => {
  it('returns null context without settings', () => {
    expect(drupalSettingsToCanvasContext(null)).toEqual({
      page: null,
      site: null,
    });
    expect(readCanvasDataV0({})).toBeNull();
    expect(readCanvasDataV0({ canvasData: { v0: { baseUrl: '/' } } })).toEqual({
      baseUrl: '/',
    });
  });

  it('builds only the groups Drupal attached', () => {
    expect(
      drupalSettingsToCanvasContext({
        baseUrl: 'https://drupal.example',
        jsonapiSettings: { apiPrefix: 'jsonapi' },
      }),
    ).toEqual({ page: null, site: null });
    expect(
      drupalSettingsToCanvasContext({
        pageTitle: null,
        breadcrumbs: null,
        mainEntity: null,
      }),
    ).toEqual({
      page: { pageTitle: '', breadcrumbs: [], mainEntity: null },
      site: null,
    });
    expect(
      drupalSettingsToCanvasContext({
        branding: { homeUrl: '/', siteName: 'Site', siteSlogan: '' },
        baseUrl: 'https://drupal.example',
      }),
    ).toEqual({
      page: null,
      site: {
        branding: { homeUrl: '/', siteName: 'Site', siteSlogan: '' },
        baseUrl: 'https://drupal.example',
        themeAssets: { logo: { url: '' }, favicon: { url: '', mimeType: '' } },
      },
    });
  });

  it('keeps theme assets when Drupal attached them', () => {
    const themeAssets = {
      logo: { url: '/logo.svg' },
      favicon: { url: '/favicon.ico', mimeType: 'image/x-icon' },
    };
    expect(
      drupalSettingsToCanvasContext({
        branding: { homeUrl: '/', siteName: 'Site', siteSlogan: '' },
        themeAssets,
      }).site?.themeAssets,
    ).toEqual(themeAssets);
  });

  it('builds the JSON:API runtime configuration', () => {
    expect(drupalSettingsToJsonApiRuntimeConfig(null)).toBeNull();
    expect(drupalSettingsToJsonApiRuntimeConfig({ branding: null })).toBeNull();
    expect(
      drupalSettingsToJsonApiRuntimeConfig({
        baseUrl: 'https://drupal.example',
        jsonapiSettings: null,
      }),
    ).toBeNull();
    expect(
      drupalSettingsToJsonApiRuntimeConfig({
        baseUrl: 'https://drupal.example',
        jsonapiSettings: { apiPrefix: 'api' },
      }),
    ).toEqual({
      baseUrl: 'https://drupal.example',
      apiPrefix: 'api',
      resourceVersion: null,
      preview: false,
    });
    expect(
      drupalSettingsToJsonApiRuntimeConfig(
        { baseUrl: 'https://drupal.example' },
        { preview: true },
      ),
    ).toEqual({
      baseUrl: 'https://drupal.example',
      resourceVersion: 'rel:working-copy',
      preview: true,
    });
  });
});
