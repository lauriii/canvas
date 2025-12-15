import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest';

import { server } from './__mocks__/server';
import { ApiService } from './api';

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
});
