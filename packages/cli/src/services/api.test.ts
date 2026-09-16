import { http, HttpResponse } from 'msw';
import {
  afterAll,
  afterEach,
  beforeAll,
  describe,
  expect,
  it,
  vi,
} from 'vitest';
import * as p from '@clack/prompts';

import { getConfig, setConfig } from '../config';
// eslint-disable-next-line vitest/no-mocks-import
import { server } from './__mocks__/server';
import {
  ApiService,
  applyPageVariantCompatibility,
  createApiService,
  supportsPageVariants,
} from './api';

describe('api service', () => {
  const mockConfig = {
    siteUrl: 'https://canvas-mock',
    clientId: 'cli',
    clientSecret: 'secret',
    scope: 'canvas:js_component canvas:asset_library',
  };

  beforeAll(() => {
    server.listen();
  });

  afterEach(() => {
    server.resetHandlers();
  });

  afterAll(() => {
    server.close();
  });

  describe('create', () => {
    it('should initialize with access token', async () => {
      const client = await ApiService.create(mockConfig);
      expect(client).toBeDefined();
      expect(client.getAccessToken()).toBe(null);

      await client.listComponents();
      expect(client.getAccessToken()).toBe('test-access-token');
    });

    it('should set custom user agent when provided', async () => {
      const customUserAgent = 'CustomCanvasCLI/1.0.0';
      const client = await ApiService.create({
        ...mockConfig,
        userAgent: customUserAgent,
      });

      // @ts-expect-error allow accessing client directly in the test.
      const userAgentHeader = client.client.defaults.headers['User-Agent'];
      expect(userAgentHeader).toBe(customUserAgent);
    });

    it('should not set user agent header when not provided', async () => {
      const client = await ApiService.create(mockConfig);

      // @ts-expect-error allow accessing client directly in the test.
      const userAgentHeader = client.client.defaults.headers['User-Agent'];
      expect(userAgentHeader).toBeUndefined();
    });

    it('should not set user agent header when empty string provided', async () => {
      const client = await ApiService.create({
        ...mockConfig,
        userAgent: '',
      });

      // @ts-expect-error allow accessing client directly in the test.
      const userAgentHeader = client.client.defaults.headers['User-Agent'];
      expect(userAgentHeader).toBeUndefined();
    });

    it('should request closed API connections', async () => {
      const client = await ApiService.create(mockConfig);

      // @ts-expect-error allow accessing client directly in the test.
      const connectionHeader = client.client.defaults.headers.Connection;
      expect(connectionHeader).toBe('close');
    });

    it('should upload media', async () => {
      const client = await ApiService.create(mockConfig);

      await expect(
        client.uploadMedia({
          mediaType: 'image',
          filename: 'hero.jpg',
          fileBuffer: Buffer.from('image-bytes'),
          data: {
            title: 'Hero',
            alt: 'Uploaded image',
          },
        }),
      ).resolves.toEqual({
        id: 42,
        uuid: 'media-uuid',
        inputs_resolved: {
          src: '/sites/default/files/image/uploaded.jpg',
          alt: 'Uploaded image',
          width: 1200,
          height: 800,
        },
      });
    });

    it('should handle invalid credentials', async () => {
      const client = await ApiService.create({
        ...mockConfig,
        clientId: 'invalid',
        clientSecret: 'invalid',
      });
      expect(client).toBeDefined();

      await expect(client.listComponents()).rejects.toThrow(
        'Authentication failed. Please check your client ID and secret.',
      );
    });

    it('should handle errors', async () => {
      const client = await ApiService.create({
        ...mockConfig,
        scope: 'canvas:this-scope-is-invalid',
      });
      expect(client).toBeDefined();

      await expect(client.listComponents()).rejects.toThrow(
        'API Error (400): invalid_scope | The requested scope is invalid, unknown, or malformed | Check the `canvas:invalid` scope',
      );
    });

    it('should handle no permission', async () => {
      const client = await ApiService.create({
        ...mockConfig,
        scope: 'canvas:this-scope-is-valid-but-no-permission',
      });
      await expect(client.listComponents()).rejects.toThrow(
        'You do not have permission to perform this action. Check your configured scope.',
      );
    });

    it('should handle network errors', async () => {
      server.close();

      const client = await ApiService.create(mockConfig);
      expect(client).toBeDefined();

      await expect(client.listComponents()).rejects.toThrow(
        'No response from: https://canvas-mock',
      );
      await expect(client.listComponents()).rejects.toThrow(
        'Check your site URL and internet connection.',
      );

      const ddevClient = await ApiService.create({
        ...mockConfig,
        siteUrl: 'https://ddev.site--not-working',
      });
      expect(ddevClient).toBeDefined();

      await expect(ddevClient.listComponents()).rejects.toThrow(
        'No response from: https://ddev.site--not-working',
      );
      await expect(ddevClient.listComponents()).rejects.toThrow(
        'Troubleshooting tips:',
      );
    });

    it('should handle failed token refresh and cleanup properly', async () => {
      const client = await ApiService.create({
        ...mockConfig,
        clientId: 'always-fail-refresh',
      });
      expect(client).toBeDefined();
      expect(client.getAccessToken()).toBe(null);

      // @ts-expect-error - accessing private property for testing
      expect(client.refreshPromise).toBe(null);

      await expect(client.listComponents()).rejects.toThrow();

      // @ts-expect-error - accessing private property for testing
      expect(client.refreshPromise).toBe(null);
    });
  });

  describe('page variant compatibility', () => {
    beforeAll(() => {
      server.listen();
    });

    afterAll(() => {
      server.close();
    });

    it('does not check page variant support without a site URL', async () => {
      await expect(supportsPageVariants()).resolves.toBe(false);
    });

    it('detects a site whose router does not recognize page variants', async () => {
      const siteUrl = `${mockConfig.siteUrl}/unsupported`;
      let authorizationHeader: string | null = null;
      server.use(
        http.get(
          `${siteUrl}/canvas/api/v0/config/page_variant`,
          ({ request }) => {
            authorizationHeader = request.headers.get('Authorization');
            return HttpResponse.json({}, { status: 404 });
          },
        ),
      );

      await expect(supportsPageVariants(siteUrl)).resolves.toBe(false);
      expect(authorizationHeader).toBeNull();
    });

    it.each([401, 403])(
      'treats an HTTP %i response as an existing protected route',
      async (status) => {
        const siteUrl = `${mockConfig.siteUrl}/protected-${status}`;
        server.use(
          http.get(`${siteUrl}/canvas/api/v0/config/page_variant`, () =>
            HttpResponse.json({}, { status }),
          ),
        );

        await expect(supportsPageVariants(siteUrl)).resolves.toBe(true);
      },
    );

    it('leaves server failures to the authenticated API request', async () => {
      const siteUrl = `${mockConfig.siteUrl}/server-error`;
      server.use(
        http.get(`${siteUrl}/canvas/api/v0/config/page_variant`, () =>
          HttpResponse.json({}, { status: 500 }),
        ),
      );

      await expect(supportsPageVariants(siteUrl)).resolves.toBe(true);
    });

    it('caches page variant support by normalized site URL', async () => {
      const siteUrl = `${mockConfig.siteUrl}/cached`;
      let requestCount = 0;
      server.use(
        http.get(`${siteUrl}/canvas/api/v0/config/page_variant`, () => {
          requestCount += 1;
          return HttpResponse.json({}, { status: 401 });
        }),
      );

      await expect(supportsPageVariants(siteUrl)).resolves.toBe(true);
      await expect(supportsPageVariants(`${siteUrl}/`)).resolves.toBe(true);
      expect(requestCount).toBe(1);
    });

    it('disables page templates, removes unsupported scopes, and warns', async () => {
      const originalConfig = { ...getConfig() };
      const warn = vi.spyOn(p.log, 'warn').mockImplementation(() => {});
      const siteUrl = `${mockConfig.siteUrl}/compatibility`;
      server.use(
        http.get(`${siteUrl}/canvas/api/v0/config/page_variant`, () =>
          HttpResponse.json({}, { status: 404 }),
        ),
      );
      setConfig({
        includePageTemplates: true,
        scope:
          'canvas:js_component canvas:page_variant canvas:media:document:create canvas:asset_library',
      });

      await applyPageVariantCompatibility(siteUrl);

      expect(getConfig().includePageTemplates).toBe(false);
      expect(getConfig().scope).toBe(
        'canvas:js_component canvas:asset_library',
      );
      expect(warn).toHaveBeenCalledWith(
        "The site at https://canvas-mock/compatibility does not serve page templates yet. Page template syncing will be skipped until the site's Drupal Canvas module is updated to 1.11 or later. Ask a site administrator if you cannot do this yourself. Alternatively, use Canvas CLI 0.23 with this site.",
      );

      warn.mockClear();
      setConfig({
        includePageTemplates: false,
        scope:
          'canvas:js_component canvas:page_variant canvas:media:document:create',
      });
      await applyPageVariantCompatibility(siteUrl);
      expect(warn).not.toHaveBeenCalled();

      warn.mockRestore();
      setConfig(originalConfig);
    });

    it('reports unsupported page template API calls', async () => {
      server.use(
        http.get(
          `${mockConfig.siteUrl}/canvas/api/v0/config/page_variant`,
          () => HttpResponse.json({}, { status: 404 }),
        ),
        http.get(
          `${mockConfig.siteUrl}/canvas/api/v0/settings/default-page-variant`,
          () => HttpResponse.json({}, { status: 404 }),
        ),
      );
      const client = await ApiService.create({
        ...mockConfig,
        accessToken: 'test-static-token',
      });
      const message =
        'The site at https://canvas-mock does not serve page templates yet. Its Drupal Canvas module must be updated to 1.11 or later. Ask a site administrator if you cannot do this yourself. Alternatively, use Canvas CLI 0.23 with this site.';

      await expect(client.listPageVariants()).rejects.toThrow(message);
      await expect(client.getDefaultPageVariant()).rejects.toThrow(message);
    });
  });

  describe('static access token', () => {
    beforeAll(() => {
      server.listen();
    });

    afterAll(() => {
      server.close();
    });

    it('should use a pre-issued access token directly without OAuth', async () => {
      const client = await ApiService.create({
        siteUrl: mockConfig.siteUrl,
        clientId: '',
        clientSecret: '',
        scope: '',
        accessToken: 'test-static-token',
      });
      expect(client.getAccessToken()).toBe('test-static-token');

      // Should succeed without hitting OAuth (refreshAccessToken throws when clientId is empty)
      await expect(client.listComponents()).resolves.toBeDefined();
    });

    it('should send the static token as Bearer on all requests', async () => {
      const client = await ApiService.create({
        siteUrl: mockConfig.siteUrl,
        clientId: '',
        clientSecret: '',
        scope: '',
        accessToken: 'test-static-token',
      });

      // @ts-expect-error - accessing private header for testing
      const authHeader = client.client.defaults.headers.common['Authorization'];
      expect(authHeader).toBe('Bearer test-static-token');
    });

    it('should fail with a descriptive error on 401', async () => {
      const client = await ApiService.create({
        siteUrl: mockConfig.siteUrl,
        clientId: '',
        clientSecret: '',
        scope: '',
        accessToken: 'invalid-static-token',
      });

      await expect(client.listComponents()).rejects.toThrow(
        'Authentication failed. Please check your access token (CANVAS_ACCESS_TOKEN).',
      );
    });
  });

  describe('createApiService', () => {
    beforeAll(() => {
      setConfig({ siteUrl: 'https://canvas-mock' });
    });

    afterEach(() => {
      vi.unstubAllEnvs();
    });

    it('should use CANVAS_ACCESS_TOKEN when set and non-empty', async () => {
      vi.stubEnv('CANVAS_ACCESS_TOKEN', 'env-static-token');

      const client = await createApiService();
      expect(client.getAccessToken()).toBe('env-static-token');
    });
  });
});
