// @cspell:ignore Aworking fother
import {
  JSONAPI_DRAFT_SESSION_EXPIRED_ERROR,
  JSONAPI_PROXY_SESSION_HEADER,
} from 'drupal-canvas/jsonapi-client';
import { describe, expect, it, vi } from 'vitest';

import {
  createJsonApiProxyHandler,
  isSameOriginRequest,
} from './jsonapi-proxy';

import type { DraftData } from '../draft-data';
import type { JsonApiProxyOptions } from './jsonapi-proxy';

const CONFIG: Awaited<ReturnType<JsonApiProxyOptions['getConfig']>> = {
  baseUrl: 'https://drupal.example',
  apiPrefix: 'jsonapi',
  jsonApiProxyPath: '/api/canvas/jsonapi',
};

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

interface Harness {
  handler: (request: Request) => Promise<Response>;
  fetchImpl: ReturnType<typeof vi.fn>;
}

function makeHarness(
  session: { enabled: boolean; draftData: DraftData | null },
  upstream: (url: string, init: RequestInit) => Response = () =>
    Response.json(
      { data: [] },
      {
        headers: {
          'Content-Type': 'application/vnd.api+json',
          'Cache-Control': 'max-age=60',
          'Set-Cookie': 'SESS=leak; Path=/',
          'X-Drupal-Cache': 'HIT',
        },
      },
    ),
  config = CONFIG,
): Harness {
  const fetchImpl = vi.fn(async (url: string, init: RequestInit) =>
    upstream(url, init),
  );
  const handler = createJsonApiProxyHandler({
    getConfig: async () => config,
    isDraftModeEnabled: async () => session.enabled,
    getDraftData: async () => session.draftData,
    fetchImpl: fetchImpl as unknown as typeof fetch,
  });
  return { handler, fetchImpl };
}

function proxyRequest(
  path: string,
  init: RequestInit & { headers?: Record<string, string> } = {},
): Request {
  return new Request(`https://app.example${path}`, {
    ...init,
    headers: {
      'sec-fetch-site': 'same-origin',
      ...init.headers,
    },
  });
}

describe('JSON:API proxy', () => {
  it.each([false, true])(
    'forwards custom language prefixes with unchanged session/header boundaries (draft: %s)',
    async (enabled) => {
      const { handler, fetchImpl } = makeHarness(
        { enabled, draftData: enabled ? liveDraftData() : null },
        undefined,
        {
          ...CONFIG,
          baseUrl: 'https://drupal.example/sub',
          jsonApiUrl: 'https://api.example/mount/api/v1',
          jsonApiSiteUrl: 'https://api.example/mount',
          jsonApiProxyPath: '/custom/proxy',
        },
      );
      for (const [path, url] of [
        [
          '/deutsch/api/v1/node/article',
          'https://api.example/mount/deutsch/api/v1/node/article',
        ],
        [
          '/deutsch/router/translate-path',
          'https://drupal.example/sub/deutsch/router/translate-path',
        ],
      ]) {
        const response = await handler(
          proxyRequest(`/custom/proxy${path}?x=1`, {
            headers: {
              authorization: 'Bearer browser-token',
              cookie: 'browser=secret',
            },
          }),
        );
        expect(response.status).toBe(200);
        expect(response.headers.has('set-cookie')).toBe(false);
        const [sentUrl, init] = fetchImpl.mock.calls.at(-1)!;
        expect(sentUrl).toBe(`${url}?x=1`);
        const headers = new Headers(init.headers);
        expect(headers.get('authorization')).toBe(
          enabled ? 'Bearer secret-token' : null,
        );
        expect(headers.has('cookie')).toBe(false);
        expect(init.redirect).toBe('manual');
        if (enabled)
          expect(response.headers.get('cache-control')).toBe(
            'private, no-store',
          );
      }
    },
  );

  it.each(['deutsch/jsonapi/../oauth/token', 'deutsch%252fother/jsonapi/node'])(
    'never fetches a malformed or non-endpoint prefixed path: %s',
    async (path) => {
      const { handler, fetchImpl } = makeHarness({
        enabled: true,
        draftData: liveDraftData(),
      });
      const response = await handler(
        proxyRequest(`/api/canvas/jsonapi/${path}`),
      );
      expect(response.status).toBe(404);
      expect(fetchImpl).not.toHaveBeenCalled();
    },
  );

  it.each([
    ['/deutsch/jsonapi/node/article', 302],
    ['/deutsch/user/login', 502],
  ] as const)(
    'keeps redirected custom prefixes inside the endpoint allowlist: %s',
    async (location, status) => {
      const { handler, fetchImpl } = makeHarness(
        { enabled: false, draftData: null },
        () => new Response(null, { status: 302, headers: { location } }),
      );
      const response = await handler(
        proxyRequest('/api/canvas/jsonapi/deutsch/jsonapi/node'),
      );
      expect(response.status).toBe(status);
      expect(response.headers.get('location')).toBe(
        status === 302 ? `/api/canvas/jsonapi${location}` : null,
      );
      expect(fetchImpl).toHaveBeenCalledTimes(1);
    },
  );

  it('forwards public requests unauthenticated and keeps them on the backend', async () => {
    const { handler, fetchImpl } = makeHarness({
      enabled: false,
      draftData: null,
    });
    const response = await handler(
      proxyRequest(
        '/api/canvas/jsonapi/de/jsonapi/node/article?include=field_tags&page[limit]=5',
        {
          headers: {
            accept: 'application/vnd.api+json',
            authorization: 'Bearer injected',
            cookie: 'SESS=fake',
            'accept-language': 'de',
          },
        },
      ),
    );
    expect(response.status).toBe(200);
    expect(fetchImpl).toHaveBeenCalledTimes(1);
    const [url, init] = fetchImpl.mock.calls[0] as [string, RequestInit];
    expect(url).toBe(
      'https://drupal.example/de/jsonapi/node/article?include=field_tags&page[limit]=5',
    );
    const sent = init.headers as Headers;
    expect(sent.get('authorization')).toBeNull();
    expect(sent.get('cookie')).toBeNull();
    expect(sent.get('accept-language')).toBe('de');
    expect(init.redirect).toBe('manual');
    expect(response.headers.get('set-cookie')).toBeNull();
    expect(response.headers.get('cache-control')).toBe('max-age=60');
    expect(response.headers.get('vary')).toBe('Cookie');
    expect(response.headers.get('x-drupal-cache')).toBe('HIT');
    expect(await response.json()).toEqual({ data: [] });
  });

  it('authenticates with the session token while the draft session is live', async () => {
    const { handler, fetchImpl } = makeHarness({
      enabled: true,
      draftData: liveDraftData(),
    });
    const response = await handler(
      proxyRequest(
        '/api/canvas/jsonapi/jsonapi/node/article/abc?resourceVersion=rel%3Aworking-copy',
        { headers: { authorization: 'Bearer injected' } },
      ),
    );
    expect(response.status).toBe(200);
    const [url, init] = fetchImpl.mock.calls[0] as [string, RequestInit];
    expect(url).toBe(
      'https://drupal.example/jsonapi/node/article/abc?resourceVersion=rel%3Aworking-copy',
    );
    expect((init.headers as Headers).get('authorization')).toBe(
      'Bearer secret-token',
    );
    expect(response.headers.get('cache-control')).toBe('private, no-store');
    expect(response.headers.get('vary')).toBe('Cookie');
  });

  it.each([
    ['expired', liveDraftData({ tokenExpiresAt: Date.now() - 1 })],
    ['invalid', null],
  ])(
    'answers a session error instead of public content for an %s session',
    async (_label, draftData) => {
      const { handler, fetchImpl } = makeHarness({ enabled: true, draftData });
      const response = await handler(
        proxyRequest('/api/canvas/jsonapi/jsonapi/node/article'),
      );
      expect(response.status).toBe(401);
      expect(response.headers.get(JSONAPI_PROXY_SESSION_HEADER)).toBe(
        'expired',
      );
      expect(response.headers.get('cache-control')).toBe('private, no-store');
      expect(await response.json()).toMatchObject({
        error: JSONAPI_DRAFT_SESSION_EXPIRED_ERROR,
      });
      expect(fetchImpl).not.toHaveBeenCalled();
    },
  );

  it('rejects paths outside the backend endpoint boundary', async () => {
    const { handler, fetchImpl } = makeHarness({
      enabled: false,
      draftData: null,
    });
    for (const path of [
      '/api/canvas/jsonapi/oauth/token',
      '/api/canvas/jsonapi/user/login',
      '/api/canvas/jsonapi/jsonapi/../oauth/token',
      '/api/canvas/jsonapi/canvas/api/v0/site-data',
      '/other/jsonapi/node/article',
    ]) {
      const response = await handler(proxyRequest(path));
      expect(response.status, path).toBe(404);
    }
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it('serves the path-translation endpoint', async () => {
    const { handler, fetchImpl } = makeHarness({
      enabled: false,
      draftData: null,
    });
    await handler(
      proxyRequest('/api/canvas/jsonapi/router/translate-path?path=/about'),
    );
    expect(fetchImpl.mock.calls[0][0]).toBe(
      'https://drupal.example/router/translate-path?path=/about',
    );
  });

  it('honors a full JSON:API URL override', async () => {
    const { handler, fetchImpl } = makeHarness(
      { enabled: false, draftData: null },
      undefined,
      {
        baseUrl: 'https://drupal.example',
        apiPrefix: 'jsonapi',
        jsonApiUrl: 'https://api.example/drupal/api',
        jsonApiProxyPath: '/api/canvas/jsonapi',
      },
    );
    const response = await handler(
      proxyRequest('/api/canvas/jsonapi/drupal/api/node/article'),
    );
    expect(response.status).toBe(200);
    expect(fetchImpl.mock.calls[0][0]).toBe(
      'https://api.example/drupal/api/node/article',
    );
    // Path translation is a supporting endpoint of the backend site, not of
    // the JSON:API override.
    const translated = await handler(
      proxyRequest('/api/canvas/jsonapi/router/translate-path?path=/about'),
    );
    expect(translated.status).toBe(200);
    expect(fetchImpl.mock.calls[1][0]).toBe(
      'https://drupal.example/router/translate-path?path=/about',
    );
    // The backend base URL's own JSON:API prefix is outside the boundary
    // while an override is configured.
    expect(
      (await handler(proxyRequest('/api/canvas/jsonapi/jsonapi/node/article')))
        .status,
    ).toBe(404);
  });

  it('localizes a foreign JSON:API override through its site base URL', async () => {
    const { handler, fetchImpl } = makeHarness(
      { enabled: false, draftData: null },
      undefined,
      {
        baseUrl: 'https://drupal.example/sub',
        jsonApiUrl: 'https://api.example/mount/api',
        jsonApiSiteUrl: 'https://api.example/mount',
        jsonApiProxyPath: '/api/canvas/jsonapi',
      },
    );
    expect(
      (await handler(proxyRequest('/api/canvas/jsonapi/fr/api/node/article')))
        .status,
    ).toBe(200);
    expect(fetchImpl.mock.calls[0][0]).toBe(
      'https://api.example/mount/fr/api/node/article',
    );
    expect(
      (
        await handler(
          proxyRequest('/api/canvas/jsonapi/fr/router/translate-path?path=/x'),
        )
      ).status,
    ).toBe(200);
    expect(fetchImpl.mock.calls[1][0]).toBe(
      'https://drupal.example/sub/fr/router/translate-path?path=/x',
    );
    expect(
      (
        await handler(
          proxyRequest('/api/canvas/jsonapi/mount/api/node/article'),
        )
      ).status,
    ).toBe(200);
    // A language prefix may itself be named "mount". It stays UNDER the
    // configured installation; it cannot replace or escape that base path.
    expect(fetchImpl.mock.calls[2][0]).toBe(
      'https://api.example/mount/mount/api/node/article',
    );
  });

  it('surfaces a rejected session token as a session error and keeps resource denials', async () => {
    const rejecting = makeHarness(
      { enabled: true, draftData: liveDraftData() },
      (url) =>
        url.includes('/node/article/a')
          ? Response.json({ errors: [{ status: '401' }] }, { status: 401 })
          : Response.json({ errors: [{ status: '403' }] }, { status: 403 }),
    );
    const rejected = await rejecting.handler(
      proxyRequest('/api/canvas/jsonapi/jsonapi/node/article/a'),
    );
    expect(rejected.status).toBe(401);
    expect(rejected.headers.get(JSONAPI_PROXY_SESSION_HEADER)).toBe('expired');
    expect(await rejected.json()).toMatchObject({
      error: JSONAPI_DRAFT_SESSION_EXPIRED_ERROR,
    });
    const denied = await rejecting.handler(
      proxyRequest('/api/canvas/jsonapi/jsonapi/node/article/b'),
    );
    expect(denied.status).toBe(403);
    expect(denied.headers.get(JSONAPI_PROXY_SESSION_HEADER)).toBeNull();
    // Unauthenticated 401s are ordinary upstream answers.
    const anonymous = makeHarness({ enabled: false, draftData: null }, () =>
      Response.json({ errors: [{ status: '401' }] }, { status: 401 }),
    );
    const plain = await anonymous.handler(
      proxyRequest('/api/canvas/jsonapi/jsonapi/node/article/a'),
    );
    expect(plain.status).toBe(401);
    expect(plain.headers.get(JSONAPI_PROXY_SESSION_HEADER)).toBeNull();
  });

  it('passes 304 through and merges the upstream Vary with Cookie', async () => {
    const { handler } = makeHarness(
      { enabled: false, draftData: null },
      () =>
        new Response(null, {
          status: 304,
          headers: {
            ETag: '"abc"',
            Vary: 'Accept-Language, accept, Cookie',
            'Cache-Control': 'max-age=30',
          },
        }),
    );
    const response = await handler(
      proxyRequest('/api/canvas/jsonapi/jsonapi/node/article', {
        headers: { 'if-none-match': '"abc"' },
      }),
    );
    expect(response.status).toBe(304);
    expect(response.headers.get('etag')).toBe('"abc"');
    expect(response.headers.get('vary')).toBe(
      'Accept-Language, accept, Cookie',
    );
    expect(response.headers.get('cache-control')).toBe('max-age=30');

    const star = makeHarness({ enabled: false, draftData: null }, () =>
      Response.json({ data: [] }, { headers: { Vary: '*' } }),
    );
    expect(
      (
        await star.handler(
          proxyRequest('/api/canvas/jsonapi/jsonapi/node/article'),
        )
      ).headers.get('vary'),
    ).toBe('*');
  });

  it('refuses cross-site and provenance-less state-changing requests', async () => {
    const { handler, fetchImpl } = makeHarness({
      enabled: true,
      draftData: liveDraftData(),
    });
    const crossSite = await handler(
      new Request(
        'https://app.example/api/canvas/jsonapi/jsonapi/node/article',
        {
          method: 'POST',
          headers: {
            'sec-fetch-site': 'cross-site',
            origin: 'https://evil.example',
          },
          body: '{}',
        },
      ),
    );
    expect(crossSite.status).toBe(403);
    const curl = await handler(
      new Request(
        'https://app.example/api/canvas/jsonapi/jsonapi/node/article',
        { method: 'PATCH', body: '{}' },
      ),
    );
    expect(curl.status).toBe(403);
    const crossSiteRead = await handler(
      new Request(
        'https://app.example/api/canvas/jsonapi/jsonapi/node/article',
        { headers: { origin: 'https://evil.example' } },
      ),
    );
    expect(crossSiteRead.status).toBe(403);
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it('forwards same-origin writes with their body and lets Drupal authorize them', async () => {
    const { handler, fetchImpl } = makeHarness(
      { enabled: true, draftData: liveDraftData() },
      () =>
        Response.json(
          { errors: [{ status: '403' }] },
          {
            status: 403,
            headers: { 'Content-Type': 'application/vnd.api+json' },
          },
        ),
    );
    const response = await handler(
      new Request(
        'https://app.example/api/canvas/jsonapi/jsonapi/node/article',
        {
          method: 'POST',
          headers: {
            origin: 'https://app.example',
            'content-type': 'application/vnd.api+json',
          },
          body: '{"data":{"type":"node--article"}}',
        },
      ),
    );
    expect(response.status).toBe(403);
    const [, init] = fetchImpl.mock.calls[0] as [string, RequestInit];
    expect(init.method).toBe('POST');
    expect(new TextDecoder().decode(init.body as ArrayBuffer)).toBe(
      '{"data":{"type":"node--article"}}',
    );
    expect((init.headers as Headers).get('content-type')).toBe(
      'application/vnd.api+json',
    );
  });

  it('rewrites redirects inside the boundary and refuses the rest', async () => {
    const inside = makeHarness(
      { enabled: false, draftData: null },
      () =>
        new Response(null, {
          status: 301,
          headers: {
            Location:
              'https://drupal.example/jsonapi/node/article?page[offset]=2',
          },
        }),
    );
    const redirected = await inside.handler(
      proxyRequest('/api/canvas/jsonapi/jsonapi/node/article'),
    );
    expect(redirected.status).toBe(301);
    expect(redirected.headers.get('location')).toBe(
      '/api/canvas/jsonapi/jsonapi/node/article?page[offset]=2',
    );

    const outside = makeHarness(
      { enabled: false, draftData: null },
      () =>
        new Response(null, {
          status: 302,
          headers: { Location: 'https://drupal.example/user/login' },
        }),
    );
    const refused = await outside.handler(
      proxyRequest('/api/canvas/jsonapi/jsonapi/node/article'),
    );
    expect(refused.status).toBe(502);
    expect(await refused.json()).toMatchObject({
      error: 'redirect_not_allowed',
    });
  });

  it('reports an unreachable backend and unsupported methods', async () => {
    const { handler } = makeHarness({ enabled: false, draftData: null }, () => {
      throw new Error('ECONNREFUSED');
    });
    const unreachable = await handler(
      proxyRequest('/api/canvas/jsonapi/jsonapi/node/article'),
    );
    expect(unreachable.status).toBe(502);
    const put = await handler(
      proxyRequest('/api/canvas/jsonapi/jsonapi/node/article', {
        method: 'PUT',
      }),
    );
    expect(put.status).toBe(405);
    const options = await handler(
      proxyRequest('/api/canvas/jsonapi/jsonapi/node/article', {
        method: 'OPTIONS',
      }),
    );
    expect(options.status).toBe(204);
  });
});

describe('isSameOriginRequest', () => {
  it('prefers Sec-Fetch-Site, then compares Origin with the forwarded host', () => {
    expect(
      isSameOriginRequest(
        new Request('https://app.example/x', {
          headers: { 'sec-fetch-site': 'same-origin' },
        }),
      ),
    ).toBe(true);
    expect(
      isSameOriginRequest(
        new Request('https://app.example/x', {
          headers: {
            'sec-fetch-site': 'same-site',
            origin: 'https://app.example',
          },
        }),
      ),
    ).toBe(false);
    expect(
      isSameOriginRequest(
        new Request('http://localhost:3000/x', {
          headers: {
            origin: 'https://app.example',
            'x-forwarded-host': 'app.example',
            'x-forwarded-proto': 'https',
          },
        }),
      ),
    ).toBe(true);
    expect(
      isSameOriginRequest(new Request('https://app.example/x')),
    ).toBeNull();
  });
});
