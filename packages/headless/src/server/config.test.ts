import { describe, expect, it, vi } from 'vitest';

import { resolveDraftConfig } from './config';

describe('resolveDraftConfig', () => {
  it('reads the Canvas site URL from the shared environment variable', () => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://drupal.example///');

    expect(resolveDraftConfig()).toEqual({
      baseUrl: 'https://drupal.example',
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
    });
    vi.unstubAllEnvs();
  });

  it('lets an explicit apiPrefix override win over the environment', () => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://drupal.example');
    vi.stubEnv('CANVAS_JSONAPI_PREFIX', 'jsonapi');

    expect(resolveDraftConfig({ apiPrefix: 'api' })).toEqual({
      baseUrl: 'https://drupal.example',
      apiPrefix: 'api',
    });
    vi.unstubAllEnvs();
  });

  it('omits apiPrefix when neither override nor environment provides one', () => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://drupal.example');
    vi.stubEnv('CANVAS_JSONAPI_PREFIX', '');

    expect(resolveDraftConfig()).toEqual({
      baseUrl: 'https://drupal.example',
    });
    vi.unstubAllEnvs();
  });
});
