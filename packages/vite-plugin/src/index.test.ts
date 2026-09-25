import { afterEach, describe, expect, it, vi } from 'vitest';

import drupalCanvas, { CANVAS_SITE_DATA_MODULE_ID } from './index';

import type { Plugin } from 'vite';

const { readStoredToken } = vi.hoisted(() => ({ readStoredToken: vi.fn() }));

vi.mock('@drupal-canvas/auth', () => ({ getTokenEntry: readStoredToken }));

afterEach(() => {
  vi.unstubAllEnvs();
  vi.restoreAllMocks();
  readStoredToken.mockReset();
});

interface TestPlugin extends Plugin {
  api: { getCanvasSiteData(): Record<string, unknown> };
}

function createPlugin(
  siteData: Record<string, unknown> | null,
  options: { status?: number; jsonapiPrefix?: string } = {},
) {
  const fetchImpl = vi.fn(
    async (_input: RequestInfo | URL, _init?: RequestInit) =>
      siteData === null
        ? new Response('nope', { status: options.status ?? 500 })
        : Response.json(siteData),
  );
  const [plugin] = drupalCanvas({
    siteUrl: 'https://drupal.example/',
    jsonapiPrefix: options.jsonapiPrefix,
    fetch: fetchImpl as unknown as typeof fetch,
  }) as TestPlugin[];
  return { plugin, fetchImpl };
}

async function runBuildStart(plugin: Plugin): Promise<void> {
  const hook = plugin.buildStart as (this: unknown) => Promise<void>;
  await hook.call({});
}

describe('drupal-canvas vite plugin', () => {
  it.each(['environment', 'stored'])(
    'fetches public metadata anonymously with %s credentials configured',
    async (source) => {
      const environmentToken =
        source === 'environment' ? 'fixture-env-token' : '';
      vi.stubEnv('CANVAS_ACCESS_TOKEN', environmentToken);
      readStoredToken.mockReturnValue({
        accessToken: 'fixture-stored-token',
        expiresAt: Date.now() + 60_000,
      });
      const { plugin, fetchImpl } = createPlugin({
        branding: { homeUrl: '/', siteName: 'Public site', siteSlogan: '' },
      });
      await runBuildStart(plugin);
      expect(fetchImpl).toHaveBeenCalledExactlyOnceWith(
        'https://drupal.example/canvas/api/v0/site-data',
        { credentials: 'omit', headers: { Accept: 'application/json' } },
      );
      const headers = new Headers(fetchImpl.mock.calls[0][1]?.headers);
      expect(headers.has('Authorization')).toBe(false);
      expect(headers.has('Cookie')).toBe(false);
      expect(readStoredToken).not.toHaveBeenCalled();
      expect(process.env.CANVAS_ACCESS_TOKEN).toBe(environmentToken);
      expect(plugin.api.getCanvasSiteData().branding).toEqual({
        homeUrl: '/',
        siteName: 'Public site',
        siteSlogan: '',
      });
    },
  );

  it('does not retry a rejected metadata request with different credentials', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    vi.stubEnv('CANVAS_ACCESS_TOKEN', 'fixture-env-token');
    const { plugin, fetchImpl } = createPlugin(null, { status: 403 });
    await runBuildStart(plugin);
    expect(fetchImpl).toHaveBeenCalledTimes(1);
    expect(fetchImpl.mock.calls[0][1]).toEqual({
      credentials: 'omit',
      headers: { Accept: 'application/json' },
    });
    expect(plugin.api.getCanvasSiteData()).toEqual({
      baseUrl: 'https://drupal.example/',
    });
    expect(console.warn).toHaveBeenCalledWith(
      expect.stringContaining('HTTP 403'),
    );
  });

  it('loads site data once and exposes it through the virtual module', async () => {
    const { plugin, fetchImpl } = createPlugin({
      baseUrl: 'https://drupal.example',
      branding: { homeUrl: '/', siteName: 'Site', siteSlogan: '' },
      jsonapiSettings: { apiPrefix: 'api' },
      themeAssets: {
        logo: { url: '/logo.svg' },
        favicon: {
          url: 'https://cdn.example/favicon.ico',
          mimeType: 'image/x-icon',
        },
      },
      capabilities: { contextHooks: true },
    });
    await runBuildStart(plugin);
    expect(fetchImpl).toHaveBeenCalledTimes(1);
    expect(fetchImpl.mock.calls[0][0]).toBe(
      'https://drupal.example/canvas/api/v0/site-data',
    );
    const resolveId = plugin.resolveId as (id: string) => string | undefined;
    const load = plugin.load as (id: string) => string | undefined;
    const resolved = resolveId(CANVAS_SITE_DATA_MODULE_ID);
    expect(resolved).toBeDefined();
    const source = load(resolved as string) as string;
    const exported = JSON.parse(
      source.replace(/^export default /, '').replace(/;$/, ''),
    );
    expect(exported).toEqual({
      baseUrl: 'https://drupal.example',
      branding: { homeUrl: '/', siteName: 'Site', siteSlogan: '' },
      jsonapiSettings: { apiPrefix: 'api' },
      themeAssets: {
        logo: { url: 'https://drupal.example/logo.svg' },
        favicon: {
          url: 'https://cdn.example/favicon.ico',
          mimeType: 'image/x-icon',
        },
      },
    });
    expect(plugin.api.getCanvasSiteData()).toEqual(exported);
    expect(load('other')).toBeUndefined();
  });

  it('falls back to static configuration when the endpoint is unreachable', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { plugin } = createPlugin(null, { jsonapiPrefix: 'api' });
    await runBuildStart(plugin);
    expect(plugin.api.getCanvasSiteData()).toEqual({
      baseUrl: 'https://drupal.example/',
      jsonapiSettings: { apiPrefix: 'api' },
    });
    const transform = plugin.transformIndexHtml as unknown as (
      html: string,
    ) => {
      tags: Array<{ children: string }>;
    };
    expect(transform('<html></html>').tags[0].children).toContain(
      'window.drupalSettings = { canvasData: { v0: {"baseUrl":"https://drupal.example/","jsonapiSettings":{"apiPrefix":"api"}} } };',
    );
  });
});
