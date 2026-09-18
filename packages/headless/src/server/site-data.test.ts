import { describe, expect, it, vi } from 'vitest';

import { createApiPrefixResolver } from './site-data';

import type { DraftConfig } from './config';

const CONFIG: DraftConfig = {
  baseUrl: 'https://drupal.example',
};

describe('createApiPrefixResolver', () => {
  it('returns the API prefix reported by the site-data endpoint', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(
        Response.json({ jsonapiSettings: { apiPrefix: 'api' } }),
      );
    const resolve = createApiPrefixResolver(
      CONFIG,
      fetchImpl as unknown as typeof fetch,
    );

    await expect(resolve()).resolves.toBe('api');
    expect(fetchImpl).toHaveBeenCalledWith(
      'https://drupal.example/canvas/api/v0/site-data',
      { headers: { Accept: 'application/json' }, cache: 'no-store' },
    );
  });

  it('prefers the fetched prefix over the configured fallback', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(
        Response.json({ jsonapiSettings: { apiPrefix: 'api' } }),
      );
    const resolve = createApiPrefixResolver(
      { ...CONFIG, apiPrefix: 'jsonapi' },
      fetchImpl as unknown as typeof fetch,
    );

    await expect(resolve()).resolves.toBe('api');
  });

  it('returns undefined when JSON:API is not installed on the site', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(Response.json({ jsonapiSettings: null }));
    const resolve = createApiPrefixResolver(
      { ...CONFIG, apiPrefix: 'api' },
      fetchImpl as unknown as typeof fetch,
    );

    await expect(resolve()).resolves.toBeUndefined();
  });

  it('falls back to the configured prefix when the endpoint fails', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(new Response('Not Found', { status: 404 }));
    const resolve = createApiPrefixResolver(
      { ...CONFIG, apiPrefix: 'api' },
      fetchImpl as unknown as typeof fetch,
    );

    await expect(resolve()).resolves.toBe('api');
    expect(warn).toHaveBeenCalledOnce();
    warn.mockRestore();
  });

  it('falls back to the configured prefix on network errors', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const fetchImpl = vi.fn().mockRejectedValue(new Error('refused'));
    const resolve = createApiPrefixResolver(
      { ...CONFIG, apiPrefix: 'api' },
      fetchImpl as unknown as typeof fetch,
    );

    await expect(resolve()).resolves.toBe('api');
    expect(warn).toHaveBeenCalledOnce();
    warn.mockRestore();
  });

  it('resolves undefined when the fetch fails and nothing is configured', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const fetchImpl = vi.fn().mockRejectedValue(new Error('refused'));
    const resolve = createApiPrefixResolver(
      CONFIG,
      fetchImpl as unknown as typeof fetch,
    );

    await expect(resolve()).resolves.toBeUndefined();
    warn.mockRestore();
  });

  it('fetches once and shares the result across calls', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(
        Response.json({ jsonapiSettings: { apiPrefix: 'api' } }),
      );
    const resolve = createApiPrefixResolver(
      CONFIG,
      fetchImpl as unknown as typeof fetch,
    );

    const [first, second, third] = await Promise.all([
      resolve(),
      resolve(),
      resolve(),
    ]);
    await resolve();

    expect([first, second, third]).toEqual(['api', 'api', 'api']);
    expect(fetchImpl).toHaveBeenCalledOnce();
  });
});
