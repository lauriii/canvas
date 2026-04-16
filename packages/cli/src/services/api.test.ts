import {
  afterAll,
  afterEach,
  beforeAll,
  describe,
  expect,
  it,
  vi,
} from 'vitest';

import { setConfig } from '../config';
// eslint-disable-next-line vitest/no-mocks-import
import { server } from './__mocks__/server';
import { ApiService, createApiService } from './api';

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
        siteUrl: 'http://ddev.site--not-working',
      });
      expect(ddevClient).toBeDefined();

      await expect(ddevClient.listComponents()).rejects.toThrow(
        'No response from: http://ddev.site--not-working',
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
