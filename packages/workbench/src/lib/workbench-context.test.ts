import { describe, expect, it } from 'vitest';

import {
  createWorkbenchContext,
  createWorkbenchJsonApiConfig,
} from './workbench-context';

describe('Workbench context', () => {
  it('uses page defaults and site data', () => {
    const context = createWorkbenchContext(
      {
        baseUrl: 'https://drupal.example',
        branding: { homeUrl: '/', siteName: 'Site', siteSlogan: 'Slogan' },
        themeAssets: {
          logo: { url: 'https://drupal.example/logo.svg' },
          favicon: { url: '', mimeType: '' },
        },
        jsonapiSettings: { apiPrefix: 'api' },
      },
      'http://localhost:5173',
    );
    expect(context.page).toEqual({
      pageTitle: '',
      breadcrumbs: [],
      mainEntity: null,
    });
    expect(context.site?.branding.siteName).toBe('Site');
    expect(context.site?.baseUrl).toBe('https://drupal.example');
    expect(context.site?.themeAssets.logo.url).toBe(
      'https://drupal.example/logo.svg',
    );
    expect(
      createWorkbenchJsonApiConfig(
        {
          baseUrl: 'https://drupal.example',
          jsonapiSettings: { apiPrefix: 'api' },
        },
        'http://localhost:5173',
      ),
    ).toEqual({
      baseUrl: 'https://drupal.example',
      apiPrefix: 'api',
      resourceVersion: null,
      preview: false,
    });
  });

  it('falls back like the legacy getters without site data', () => {
    const context = createWorkbenchContext(null, 'http://localhost:5173');
    expect(context.site).toEqual({
      branding: { homeUrl: '', siteName: '', siteSlogan: '' },
      baseUrl: 'http://localhost:5173',
      themeAssets: { logo: { url: '' }, favicon: { url: '', mimeType: '' } },
    });
    expect(createWorkbenchJsonApiConfig(null, 'http://localhost:5173')).toEqual(
      {
        baseUrl: 'http://localhost:5173',
        resourceVersion: null,
        preview: false,
      },
    );
    expect(
      createWorkbenchJsonApiConfig(
        { baseUrl: 'https://drupal.example', jsonapiSettings: null },
        'http://localhost:5173',
      ),
    ).toBeNull();
  });
});
