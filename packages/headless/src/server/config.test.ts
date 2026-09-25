import { describe, expect, it, vi } from 'vitest';

import { resolveDraftConfig } from './config';

describe('resolveDraftConfig', () => {
  it('reads the Canvas site URL from the shared environment variable', () => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://drupal.example///');

    expect(resolveDraftConfig()).toEqual({
      baseUrl: 'https://drupal.example',
      jsonApiProxyPath: '/api/canvas/jsonapi',
    });
    vi.unstubAllEnvs();
  });

  it('names CANVAS_SITE_URL when the required setting is missing', () => {
    vi.stubEnv('CANVAS_SITE_URL', '');

    expect(() => resolveDraftConfig()).toThrow(
      'CANVAS_SITE_URL must be set. See .env.example.',
    );
    vi.unstubAllEnvs();
  });

  it('reads the JSON:API prefix fallback from the environment', () => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://drupal.example');
    vi.stubEnv('CANVAS_JSONAPI_PREFIX', '/api/');

    expect(resolveDraftConfig()).toEqual({
      baseUrl: 'https://drupal.example',
      apiPrefix: 'api',
      jsonApiProxyPath: '/api/canvas/jsonapi',
    });
    vi.unstubAllEnvs();
  });

  it('lets an explicit apiPrefix override win over the environment', () => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://drupal.example');
    vi.stubEnv('CANVAS_JSONAPI_PREFIX', 'jsonapi');

    expect(resolveDraftConfig({ apiPrefix: 'api' })).toEqual({
      baseUrl: 'https://drupal.example',
      apiPrefix: 'api',
      jsonApiProxyPath: '/api/canvas/jsonapi',
    });
    vi.unstubAllEnvs();
  });

  it('omits apiPrefix when neither override nor environment provides one', () => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://drupal.example');
    vi.stubEnv('CANVAS_JSONAPI_PREFIX', '');

    expect(resolveDraftConfig()).toEqual({
      baseUrl: 'https://drupal.example',
      jsonApiProxyPath: '/api/canvas/jsonapi',
    });
    vi.unstubAllEnvs();
  });

  it('reads and validates the JSON:API site base URL from the environment', () => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://drupal.example/sub');
    vi.stubEnv('CANVAS_JSONAPI_URL', 'https://api.example/mount/api');
    vi.stubEnv('CANVAS_JSONAPI_SITE_URL', 'https://api.example/mount/');
    expect(resolveDraftConfig()).toMatchObject({
      jsonApiUrl: 'https://api.example/mount/api',
      jsonApiSiteUrl: 'https://api.example/mount',
    });
    vi.stubEnv('CANVAS_JSONAPI_SITE_URL', 'https://api.example/elsewhere');
    expect(() => resolveDraftConfig()).toThrow(/not under its site base URL/);
    vi.unstubAllEnvs();
  });

  it('reads the full JSON:API URL override and the proxy path from the environment', () => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://drupal.example');
    vi.stubEnv('CANVAS_JSONAPI_URL', 'https://api.example/drupal/jsonapi/');
    vi.stubEnv('CANVAS_JSONAPI_PROXY_PATH', '/canvas-proxy/');

    expect(resolveDraftConfig()).toEqual({
      baseUrl: 'https://drupal.example',
      jsonApiUrl: 'https://api.example/drupal/jsonapi',
      jsonApiProxyPath: '/canvas-proxy',
    });
    vi.unstubAllEnvs();
  });

  it('rejects a proxy path that is not site-relative', () => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://drupal.example');
    expect(() =>
      resolveDraftConfig({ jsonApiProxyPath: 'https://evil.example/' }),
    ).toThrow('CANVAS_JSONAPI_PROXY_PATH');
    vi.unstubAllEnvs();
  });
});
