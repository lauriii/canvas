import { describe, expect, it } from 'vitest';

import {
  getDraftClient,
  getPublicClient,
  resolveJsonApiEndpoints,
  resolveJsonApiRuntimeConfig,
  resolveJsonApiUrl,
} from './json-api-client';

import type { DraftData } from '../draft-data';

function liveDraftData(overrides: Partial<DraftData> = {}): DraftData {
  return {
    path: '/node/9',
    resourceVersion: 'rel:working-copy',
    sub: '42',
    renewUrl: 'https://drupal.example/canvas-headless/renew',
    accessToken: 'secret-token',
    tokenType: 'Bearer',
    tokenExpiresAt: Date.now() + 600_000,
    codeVerifier: 'stored-verifier',
    ...overrides,
  };
}

describe('JSON:API client factories', () => {
  it('resolves endpoints with an explicit full URL override winning', () => {
    expect(
      resolveJsonApiEndpoints({
        baseUrl: 'https://drupal.example',
        apiPrefix: 'api',
      }),
    ).toEqual({ baseUrl: 'https://drupal.example', apiPrefix: 'api' });
    expect(
      resolveJsonApiEndpoints({
        baseUrl: 'https://drupal.example',
        apiPrefix: 'api',
        jsonApiUrl: 'https://cdn.example/drupal/jsonapi/',
      }),
    ).toEqual({
      // The override serves JSON:API only; supporting endpoints (path
      // translation) stay on the backend base URL.
      baseUrl: 'https://drupal.example',
      apiPrefix: 'drupal/jsonapi',
      apiUrl: 'https://cdn.example/drupal/jsonapi',
    });
    expect(
      resolveJsonApiUrl({
        baseUrl: 'https://drupal.example',
        apiPrefix: 'drupal/jsonapi',
        apiUrl: 'https://cdn.example/drupal/jsonapi',
      }),
    ).toBe('https://cdn.example/drupal/jsonapi');
    // A foreign override with its site base URL is localizable there.
    expect(
      resolveJsonApiEndpoints({
        baseUrl: 'https://drupal.example/sub',
        jsonApiUrl: 'https://api.example/mount/api',
        jsonApiSiteUrl: 'https://api.example/mount',
      }),
    ).toEqual({
      baseUrl: 'https://drupal.example/sub',
      apiPrefix: 'api',
      apiUrl: 'https://api.example/mount/api',
      apiSiteUrl: 'https://api.example/mount',
    });
    expect(() =>
      resolveJsonApiEndpoints({
        baseUrl: 'https://drupal.example/sub',
        jsonApiUrl: 'https://api.example/other/api',
        jsonApiSiteUrl: 'https://api.example/mount',
      }),
    ).toThrow(/not under its site base URL/);
    // Under the backend base URL (site in a subdirectory), the override is a
    // prefix relative to the site.
    expect(
      resolveJsonApiEndpoints({
        baseUrl: 'https://drupal.example/sub',
        jsonApiUrl: 'https://drupal.example/sub/api',
      }),
    ).toEqual({
      baseUrl: 'https://drupal.example/sub',
      apiPrefix: 'api',
      apiUrl: 'https://drupal.example/sub/api',
    });
    expect(
      resolveJsonApiUrl({
        baseUrl: 'https://drupal.example',
        apiPrefix: 'api',
      }),
    ).toBe('https://drupal.example/api');
    expect(resolveJsonApiUrl({ baseUrl: 'https://drupal.example' })).toBe(
      'https://drupal.example/jsonapi',
    );
  });

  it('creates public and draft clients on the shared implementation', () => {
    const publicClient = getPublicClient({
      baseUrl: 'https://drupal.example',
      apiPrefix: 'api',
    });
    expect(publicClient.baseUrl).toBe('https://drupal.example');
    expect(publicClient.apiPrefix).toBe('api');
    expect(publicClient.resourceVersion).toBeNull();
    expect(publicClient.authentication).toBeUndefined();
    expect(publicClient.serializer).toBeDefined();

    const draftClient = getDraftClient(
      { baseUrl: 'https://drupal.example' },
      liveDraftData(),
    );
    expect(draftClient.resourceVersion).toBe('rel:working-copy');
    expect(draftClient.authentication).toEqual({
      type: 'Custom',
      credentials: { value: 'Bearer secret-token' },
    });
    expect(draftClient.runtimeConfig.preview).toBe(true);
    expect(() =>
      getDraftClient(
        { baseUrl: 'https://drupal.example' },
        liveDraftData({ tokenExpiresAt: Date.now() - 1 }),
      ),
    ).toThrow('expired');
  });

  it('builds nonsecret runtime configuration for the browser client', () => {
    const config = {
      baseUrl: 'https://drupal.example',
      apiPrefix: 'api',
      jsonApiProxyPath: '/api/canvas/jsonapi',
    };
    expect(resolveJsonApiRuntimeConfig(config, null)).toEqual({
      baseUrl: 'https://drupal.example',
      apiPrefix: 'api',
      proxyUrl: '/api/canvas/jsonapi',
      resourceVersion: null,
      preview: false,
    });
    const live = resolveJsonApiRuntimeConfig(config, liveDraftData());
    expect(live).toEqual({
      baseUrl: 'https://drupal.example',
      apiPrefix: 'api',
      proxyUrl: '/api/canvas/jsonapi',
      resourceVersion: 'rel:working-copy',
      preview: true,
    });
    expect(JSON.stringify(live)).not.toContain('secret-token');
    expect(
      resolveJsonApiRuntimeConfig(
        config,
        liveDraftData({ tokenExpiresAt: Date.now() - 1 }),
      ),
    ).toMatchObject({ resourceVersion: null, preview: false });
  });
});
