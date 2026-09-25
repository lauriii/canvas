import { afterEach, describe, expect, it, vi } from 'vitest';

import { DRAFT_DATA_COOKIE_NAME } from '../constants';
import { serializeDraftData } from '../draft-data';
import { resolveDraftConfig } from './config';
import { createDraftServer, redeemAssertion } from './flows';
import { codeChallenge } from './pkce';

import type { DraftData } from '../draft-data';
import type { DraftServerAdapter } from './adapter';
import type { DraftConfig } from './config';
import type { DraftCookie } from './cookies';

const CONFIG: DraftConfig = {
  baseUrl: 'https://drupal.example',
};

const FLAG_COOKIE = '__test_bypass';

const validClaims = {
  path: '/node/1',
  resourceVersion: 'rel:working-copy',
  previewContext: {
    language: 'fr',
    viewMode: 'teaser',
    pageVariant: 'alternate',
  },
  sub: '42',
  renewUrl: 'https://drupal.example/canvas-headless/renew',
};

function buildAssertion(claims: Record<string, unknown>): string {
  const encode = (value: unknown) =>
    Buffer.from(JSON.stringify(value)).toString('base64url');
  return `${encode({ alg: 'RS256' })}.${encode(claims)}.signature`;
}

function tokenResponse() {
  return Response.json({
    token_type: 'Bearer',
    expires_in: 900,
    access_token: 'access-token-value',
  });
}

function liveDraftData(
  overrides: Partial<DraftData> & { previewContext?: unknown } = {},
): DraftData {
  return {
    path: '/node/9',
    resourceVersion: 'rel:working-copy',
    sub: '42',
    renewUrl: 'https://drupal.example/canvas-headless/renew',
    accessToken: 'old-token',
    tokenType: 'Bearer',
    tokenExpiresAt: Date.now() + 600_000,
    codeVerifier: 'stored-verifier',
    ...overrides,
  };
}

function makeAdapter() {
  const cookies = new Map<string, DraftCookie>();
  let flag = false;
  const adapter: DraftServerAdapter = {
    getCookie: async (name) => cookies.get(name)?.value ?? null,
    setCookie: async (cookie) => {
      cookies.set(cookie.name, cookie);
    },
    isDraftFlagEnabled: async () => flag,
    enableDraftFlag: async () => {
      flag = true;
      // Real frameworks set their flag cookie with default attributes; the
      // flows are expected to re-set it cross-site.
      if (!cookies.has(FLAG_COOKIE)) {
        cookies.set(FLAG_COOKIE, {
          name: FLAG_COOKIE,
          value: 'bypass-value',
          httpOnly: true,
          path: '/',
          sameSite: 'none',
          secure: false,
          partitioned: false,
        });
      }
    },
    disableDraftFlag: async () => {
      flag = false;
    },
    draftFlagCookieName: FLAG_COOKIE,
    redirect: (path) =>
      new Response(null, { status: 307, headers: { Location: path } }),
  };
  return {
    adapter,
    cookies,
    getFlag: () => flag,
    seedSession: (draftData: DraftData) => {
      flag = true;
      cookies.set(DRAFT_DATA_COOKIE_NAME, {
        name: DRAFT_DATA_COOKIE_NAME,
        value: JSON.stringify(draftData),
        httpOnly: true,
        path: '/',
        sameSite: 'none',
        secure: true,
        partitioned: true,
      });
    },
  };
}

function makeServer(fetchImpl: typeof fetch, config: DraftConfig = CONFIG) {
  const harness = makeAdapter();
  const server = createDraftServer({
    adapter: harness.adapter,
    config,
    fetchImpl,
  });
  return { ...harness, server };
}

describe('redeemAssertion', () => {
  it('builds the draft session from the token response and the claims', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(tokenResponse());
    const before = Date.now();

    const result = await redeemAssertion(
      buildAssertion(validClaims),
      CONFIG,
      fetchImpl as unknown as typeof fetch,
    );

    expect(result.ok).toBe(true);
    if (result.ok) {
      expect(result.draftData).toMatchObject({
        path: '/node/1',
        resourceVersion: 'rel:working-copy',
        sub: '42',
        renewUrl: validClaims.renewUrl,
        accessToken: 'access-token-value',
        tokenType: 'Bearer',
      });
      expect(result.draftData.tokenExpiresAt).toBeGreaterThanOrEqual(
        before + 900_000,
      );
      expect(result.draftData.tokenExpiresAt).toBeLessThanOrEqual(
        Date.now() + 900_000,
      );
    }

    const [url, init] = fetchImpl.mock.calls[0];
    expect(url).toBe('https://drupal.example/oauth/token');
    expect(init.cache).toBe('no-store');
    const body = new URLSearchParams(init.body);
    expect(body.get('grant_type')).toBe(
      'urn:ietf:params:oauth:grant-type:jwt-bearer',
    );
    expect(body.get('client_id')).toBe('canvas_headless');

    // Every exchange registers an S256 challenge for the next renewal, and
    // the stored verifier hashes to it.
    expect(body.get('code_challenge_method')).toBe('S256');
    if (result.ok) {
      expect(body.get('code_challenge')).toBe(
        await codeChallenge(result.draftData.codeVerifier),
      );
    }
    // An activation exchange carries no verifier: none was passed in.
    expect(body.get('code_verifier')).toBeNull();
  });

  it.each([42, null, ['fr']])(
    'does not retain an invalid language claim (%s)',
    async (language) => {
      const result = await redeemAssertion(
        buildAssertion({ ...validClaims, previewContext: { language } }),
        CONFIG,
        vi.fn().mockResolvedValue(tokenResponse()),
      );
      expect(result.ok).toBe(true);
      if (result.ok) {
        expect(result.draftData).not.toHaveProperty('previewContext');
      }
    },
  );

  it.each([false, true, 'false', 0, null])(
    'does not store an obsolete session-wide excludeAutoSave claim (%s)',
    async (excludeAutoSave) => {
      const result = await redeemAssertion(
        buildAssertion({ ...validClaims, previewContext: { excludeAutoSave } }),
        CONFIG,
        vi.fn().mockResolvedValue(tokenResponse()),
      );
      expect(result.ok).toBe(true);
      if (result.ok) {
        expect(result.draftData).not.toHaveProperty('previewContext');
      }
    },
  );

  it('presents the previous verifier when one is passed', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(tokenResponse());

    const result = await redeemAssertion(
      buildAssertion(validClaims),
      CONFIG,
      fetchImpl as unknown as typeof fetch,
      'previous-verifier',
    );

    expect(result.ok).toBe(true);
    const body = new URLSearchParams(fetchImpl.mock.calls[0][1].body);
    expect(body.get('code_verifier')).toBe('previous-verifier');
    // The verifier rotates: the new session stores a fresh one.
    if (result.ok) {
      expect(result.draftData.codeVerifier).not.toBe('previous-verifier');
    }
  });

  it('answers 502 when Drupal is unreachable', async () => {
    const fetchImpl = vi.fn().mockRejectedValue(new Error('refused'));
    const result = await redeemAssertion(
      buildAssertion(validClaims),
      CONFIG,
      fetchImpl as unknown as typeof fetch,
    );
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.response.status).toBe(502);
    }
  });

  it('passes the upstream refusal through with its detail', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(
      Response.json(
        {
          error: 'invalid_grant',
          error_description: 'The assertion was already used.',
          hint: 'Mint a fresh one.',
        },
        { status: 400 },
      ),
    );
    const result = await redeemAssertion(
      buildAssertion(validClaims),
      CONFIG,
      fetchImpl as unknown as typeof fetch,
    );
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.response.status).toBe(400);
      expect(await result.response.text()).toBe(
        'The assertion was already used. Mint a fresh one.',
      );
    }
  });

  it.each([
    ['a missing path', { ...validClaims, path: undefined }],
    ['a protocol-relative path', { ...validClaims, path: '//evil.example' }],
    ['a backslash path', { ...validClaims, path: '/node\\1' }],
    ['a relative path', { ...validClaims, path: 'node/1' }],
    [
      'a missing resourceVersion',
      { ...validClaims, resourceVersion: undefined },
    ],
    ['an empty sub', { ...validClaims, sub: '' }],
    ['a missing renewUrl', { ...validClaims, renewUrl: undefined }],
    [
      'a non-http renewUrl',
      { ...validClaims, renewUrl: 'javascript:alert(1)' },
    ],
  ])('answers 422 for %s', async (_label, claims) => {
    const fetchImpl = vi.fn().mockResolvedValue(tokenResponse());
    const result = await redeemAssertion(
      buildAssertion(claims as Record<string, unknown>),
      CONFIG,
      fetchImpl as unknown as typeof fetch,
    );
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.response.status).toBe(422);
    }
  });
});

describe('enableDraftMode', () => {
  it('answers 422 without an assertion', async () => {
    const { server } = makeServer(vi.fn() as unknown as typeof fetch);
    const response = await server.enableDraftMode(
      new Request('https://app.example/api/draft'),
    );
    expect(response.status).toBe(422);
  });

  it.each([
    '/node/1',
    '/node/1?filter=a%20b&_canvas_language=pl&_canvas_viewMode=teaser&_canvas_pageVariant=alternate&_canvas_excludeAutoSave=true#part',
  ])(
    'stores only shared session fields and redirects to signed path %s',
    async (path) => {
      const fetchImpl = vi.fn().mockResolvedValue(tokenResponse());
      const { server, cookies, getFlag } = makeServer(
        fetchImpl as unknown as typeof fetch,
      );

      const assertion = buildAssertion({ ...validClaims, path });
      const response = await server.enableDraftMode(
        new Request(
          `https://app.example/api/draft?assertion=${encodeURIComponent(assertion)}`,
        ),
      );

      expect(response.status).toBe(307);
      expect(response.headers.get('Location')).toBe(path);
      expect(getFlag()).toBe(true);

      // The framework flag cookie was re-set with the cross-site attributes.
      const flagCookie = cookies.get(FLAG_COOKIE);
      expect(flagCookie).toMatchObject({
        value: 'bypass-value',
        sameSite: 'none',
        secure: true,
        partitioned: true,
        httpOnly: true,
        path: '/',
      });

      const dataCookie = cookies.get(DRAFT_DATA_COOKIE_NAME);
      expect(dataCookie).toMatchObject({
        sameSite: 'none',
        secure: true,
        partitioned: true,
      });
      expect(JSON.parse(dataCookie!.value)).toMatchObject({
        path: path.includes('?') ? '/node/1?filter=a%20b#part' : '/node/1',
      });
      expect(JSON.parse(dataCookie!.value)).not.toHaveProperty(
        'previewContext',
      );
    },
  );

  it.each([
    [null, '/node/9'],
    ['/example', '/example'],
    [
      '/example?_canvas_excludeAutoSave=true',
      '/example?_canvas_excludeAutoSave=true',
    ],
    ['//evil.example', '/node/9'],
    ['/\n/evil.example', '/node/9'],
    ['/\n/[bad', '/node/9'],
    ['/\\evil.example', '/node/9'],
  ])(
    'restores this tab after a rejected assertion (%s)',
    async (path, expected) => {
      const fetchImpl = vi
        .fn()
        .mockResolvedValue(
          Response.json({ error: 'invalid_grant' }, { status: 400 }),
        );
      const { server, seedSession } = makeServer(
        fetchImpl as unknown as typeof fetch,
      );
      seedSession(liveDraftData({ path: '/node/9' }));

      const response = await server.enableDraftMode(
        new Request(
          `https://app.example/api/draft?assertion=${path === null ? 'dead' : buildAssertion({ ...validClaims, path })}`,
        ),
      );

      expect(response.status).toBe(307);
      expect(response.headers.get('Location')).toBe(expected);
      expect(await server.getDraftData()).toMatchObject({
        path: '/node/9',
        accessToken: 'old-token',
      });
    },
  );

  it('surfaces the redemption failure without a live session', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(
        Response.json({ error: 'invalid_grant' }, { status: 400 }),
      );
    const { server, seedSession } = makeServer(
      fetchImpl as unknown as typeof fetch,
    );
    seedSession(liveDraftData({ tokenExpiresAt: Date.now() - 1 }));

    const response = await server.enableDraftMode(
      new Request('https://app.example/api/draft?assertion=dead'),
    );
    expect(response.status).toBe(400);
  });
});

describe('renewDraftSession', () => {
  const renewRequest = (body: unknown) =>
    new Request('https://app.example/api/draft/renew', {
      method: 'POST',
      body: JSON.stringify(body),
    });

  it('answers 422 without an assertion in the body', async () => {
    const { server, seedSession } = makeServer(
      vi.fn() as unknown as typeof fetch,
    );
    seedSession(liveDraftData());
    const response = await server.renewDraftSession(renewRequest({}));
    expect(response.status).toBe(422);
  });

  it('refuses to renew without an existing session', async () => {
    const { server } = makeServer(vi.fn() as unknown as typeof fetch);
    const response = await server.renewDraftSession(
      renewRequest({ assertion: buildAssertion(validClaims) }),
    );
    expect(response.status).toBe(400);
  });

  it('refuses an assertion naming a different editor, unconsumed', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(tokenResponse());
    const { server, seedSession } = makeServer(
      fetchImpl as unknown as typeof fetch,
    );
    seedSession(liveDraftData({ sub: '42' }));

    const response = await server.renewDraftSession(
      renewRequest({ assertion: buildAssertion({ ...validClaims, sub: '7' }) }),
    );

    expect(response.status).toBe(409);
    // The mismatched assertion was never presented at the token endpoint.
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it.each(
    [undefined, false, true].flatMap((previousExcludeAutoSave) =>
      [undefined, false, true].map((excludeAutoSave) => ({
        previousExcludeAutoSave,
        excludeAutoSave,
      })),
    ),
  )(
    'drops obsolete exclusion policy $previousExcludeAutoSave on renewal with claim $excludeAutoSave',
    async ({ previousExcludeAutoSave, excludeAutoSave }) => {
      const fetchImpl = vi.fn().mockResolvedValue(tokenResponse());
      const { server, seedSession, cookies } = makeServer(
        fetchImpl as unknown as typeof fetch,
      );
      seedSession(
        liveDraftData({
          sub: '42',
          previewContext: {
            ...validClaims.previewContext,
            ...{ excludeAutoSave: previousExcludeAutoSave },
          },
        }),
      );

      const response = await server.renewDraftSession(
        renewRequest({
          assertion: buildAssertion({
            ...validClaims,
            previewContext: {
              ...validClaims.previewContext,
              ...(excludeAutoSave !== undefined && { excludeAutoSave }),
            },
          }),
        }),
      );

      expect(response.status).toBe(200);
      const body = (await response.json()) as { tokenExpiresAt: number };
      expect(body.tokenExpiresAt).toBeGreaterThan(Date.now());
      expect(
        JSON.parse(cookies.get(DRAFT_DATA_COOKIE_NAME)!.value),
      ).toMatchObject({ accessToken: 'access-token-value' });

      // The renewal exchange spends the session's stored verifier at Drupal,
      // and the session continues with a rotated one.
      const exchangeBody = new URLSearchParams(fetchImpl.mock.calls[0][1].body);
      expect(exchangeBody.get('code_verifier')).toBe('stored-verifier');
      const stored = JSON.parse(
        cookies.get(DRAFT_DATA_COOKIE_NAME)!.value,
      ) as DraftData;
      expect(typeof stored.codeVerifier).toBe('string');
      expect(stored.codeVerifier).not.toBe('stored-verifier');
      expect(stored).not.toHaveProperty('previewContext');
    },
  );
});

describe('disableDraftMode', () => {
  it('overwrites both cookies expired with matching partition attributes', async () => {
    const { server, seedSession, cookies, getFlag } = makeServer(
      vi.fn() as unknown as typeof fetch,
    );
    seedSession(liveDraftData());
    cookies.set(FLAG_COOKIE, {
      name: FLAG_COOKIE,
      value: 'bypass-value',
      httpOnly: true,
      path: '/',
      sameSite: 'none',
      secure: true,
      partitioned: true,
    });

    const response = await server.disableDraftMode();

    // A 303, not the adapter's redirect: the exit route is a POST, and the
    // browser must follow with a GET.
    expect(response.status).toBe(303);
    expect(response.headers.get('Location')).toBe('/');
    expect(getFlag()).toBe(false);
    for (const name of [FLAG_COOKIE, DRAFT_DATA_COOKIE_NAME]) {
      expect(cookies.get(name)).toMatchObject({
        value: '',
        expires: new Date(0),
        sameSite: 'none',
        secure: true,
        partitioned: true,
      });
    }
  });
});

describe('getDraftData', () => {
  it('returns null while the draft flag is off', async () => {
    const { server, cookies } = makeServer(vi.fn() as unknown as typeof fetch);
    cookies.set(DRAFT_DATA_COOKIE_NAME, {
      name: DRAFT_DATA_COOKIE_NAME,
      value: serializeDraftData(liveDraftData()),
      httpOnly: true,
      path: '/',
      sameSite: 'none',
      secure: true,
      partitioned: true,
    });
    expect(await server.getDraftData()).toBeNull();
  });

  it('returns the parsed session while the flag is on', async () => {
    const { server, seedSession } = makeServer(
      vi.fn() as unknown as typeof fetch,
    );
    const draftData = liveDraftData();
    seedSession(draftData);
    expect(await server.getDraftData()).toEqual(draftData);
  });
});

describe('getClient', () => {
  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it.each(['environment', 'callback'])(
    'defers %s configuration until client access',
    async (source) => {
      vi.stubEnv('CANVAS_SITE_URL', undefined);
      const config = vi.fn(() => resolveDraftConfig());
      const fetchImpl = vi
        .fn()
        .mockResolvedValue(
          Response.json({ jsonapiSettings: { apiPrefix: 'api' } }),
        );
      const server = createDraftServer({
        adapter: makeAdapter().adapter,
        ...(source === 'callback' && { config }),
        fetchImpl,
      });

      expect(config).not.toHaveBeenCalled();
      expect(fetchImpl).not.toHaveBeenCalled();
      await expect(server.getPublicClient()).rejects.toThrow(
        'CANVAS_SITE_URL must be set.',
      );
      expect(fetchImpl).not.toHaveBeenCalled();

      vi.stubEnv('CANVAS_SITE_URL', CONFIG.baseUrl);
      const client = await server.getPublicClient();
      expect(client.apiPrefix).toBe('api');
      expect(fetchImpl).toHaveBeenCalledExactlyOnceWith(
        `${CONFIG.baseUrl}/canvas/api/v0/site-data`,
        {
          headers: { Accept: 'application/json' },
          cache: 'no-store',
        },
      );
    },
  );

  it('resolves the JSON:API prefix from the site-data endpoint', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(
        Response.json({ jsonapiSettings: { apiPrefix: 'api' } }),
      );
    const { server } = makeServer(fetchImpl as unknown as typeof fetch);

    const publicClient = await server.getPublicClient();
    expect(publicClient.apiPrefix).toBe('api');

    const { server: draftServer, seedSession } = makeServer(
      vi
        .fn()
        .mockResolvedValue(
          Response.json({ jsonapiSettings: { apiPrefix: 'api' } }),
        ) as unknown as typeof fetch,
    );
    seedSession(liveDraftData());
    const draftClient = await draftServer.getClient();
    expect(draftClient.apiPrefix).toBe('api');
  });

  it('shares in-flight prefix discovery and caches it per server instance', async () => {
    let resolveResponse!: (response: Response) => void;
    const response = new Promise<Response>((resolve) => {
      resolveResponse = resolve;
    });
    const fetchImpl = vi.fn().mockReturnValue(response);
    const { server, seedSession } = makeServer(
      fetchImpl as unknown as typeof fetch,
    );
    seedSession(liveDraftData());

    const clients = Promise.all([
      server.getPublicClient(),
      server.getClient(),
      server.getDraftClient(liveDraftData()),
    ]);
    expect(fetchImpl).toHaveBeenCalledTimes(1);
    resolveResponse(Response.json({ jsonapiSettings: { apiPrefix: 'api' } }));
    for (const client of await clients) {
      expect(client.apiPrefix).toBe('api');
    }

    expect((await server.getPublicClient()).apiPrefix).toBe('api');
    expect(fetchImpl).toHaveBeenCalledTimes(1);
  });

  it('falls back to the configured prefix when the fetch fails', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const fetchImpl = vi.fn().mockRejectedValue(new Error('refused'));
    const { server } = makeServer(fetchImpl as unknown as typeof fetch, {
      ...CONFIG,
      apiPrefix: 'api',
    });

    const client = await server.getPublicClient();
    expect(client.apiPrefix).toBe('api');
    warn.mockRestore();
  });

  it('keeps the /jsonapi default when nothing provides a prefix', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const fetchImpl = vi.fn().mockRejectedValue(new Error('refused'));
    const { server } = makeServer(fetchImpl as unknown as typeof fetch);

    const client = await server.getPublicClient();
    expect(client.apiPrefix).toBe('jsonapi');
    warn.mockRestore();
  });
});

describe('fetchPage', () => {
  const page = {
    content: { element: 'canvas-page' },
    head: { title: 'Example page' },
    context: {
      page: {
        pageTitle: 'Example page',
        breadcrumbs: [{ key: '<front>', text: 'Home', url: '/' }],
        mainEntity: null,
      },
      site: {
        branding: { homeUrl: '/', siteName: 'Example', siteSlogan: '' },
        baseUrl: 'https://drupal.example',
        themeAssets: {
          logo: { url: 'https://drupal.example/sites/default/files/logo.svg' },
          favicon: {
            url: 'https://cdn.example/icon.png',
            mimeType: 'image/png',
          },
        },
      },
    },
    route: {
      name: 'entity.canvas_page.canonical',
      requestUri: '/example',
      params: { canvas_page: '1' },
      managedByCanvas: true,
      entity: {
        entityType: 'canvas_page',
        bundle: 'canvas_page',
        id: '1',
        uuid: 'page-uuid',
        langcode: 'en',
      },
    },
  };

  it('keeps a public component tree marker-free', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(Response.json(page));
    const { server } = makeServer(fetchImpl as unknown as typeof fetch);

    await expect(server.fetchPage('/example')).resolves.toEqual(page);
    expect(fetchImpl).toHaveBeenCalledWith(
      new URL(
        'https://drupal.example/canvas/content-api?requestUri=%2Fexample',
      ),
      expect.objectContaining({
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      }),
    );
  });

  it('returns public responses without Canvas content unchanged', async () => {
    const emptyPage = { ...page, content: null };
    const fetchImpl = vi.fn().mockResolvedValue(Response.json(emptyPage));
    const { server } = makeServer(fetchImpl as unknown as typeof fetch);

    await expect(server.fetchPage('/example')).resolves.toEqual(emptyPage);
  });

  it('normalizes a Canvas 1.11 response without context to null page and site slots', async () => {
    // The release response contains content/head/route, but no context.
    const legacyPage = {
      content: page.content,
      head: page.head,
      route: page.route,
    };
    const fetchImpl = vi.fn().mockResolvedValue(Response.json(legacyPage));
    const { server } = makeServer(fetchImpl as unknown as typeof fetch);

    await expect(server.fetchPage('/example')).resolves.toEqual({
      ...legacyPage,
      context: { page: null, site: null },
    });
  });

  it.each([
    [false, false, true],
    [true, false, true],
    [true, true, true],
    [true, false, false],
  ])(
    'retains request mode on local redirects (saved=%s, external=%s, live=%s)',
    async (saved, external, live) => {
      const target = external
        ? 'https://elsewhere.example/new-location'
        : '/new-location?filter=a%20b#section';
      const redirect = { redirect: { external, url: target, statusCode: 301 } };
      const fetchImpl = vi.fn().mockResolvedValue(Response.json(redirect));
      const { server, seedSession } = makeServer(fetchImpl);
      seedSession(
        liveDraftData({ tokenExpiresAt: Date.now() + (live ? 60_000 : -1) }),
      );
      const path = saved
        ? '/old-location?_canvas_excludeAutoSave=true'
        : '/old-location';
      await expect(server.fetchPage(path)).resolves.toEqual({
        redirect: {
          ...redirect.redirect,
          url:
            saved && live && !external
              ? '/new-location?filter=a%20b&_canvas_excludeAutoSave=true#section'
              : target,
        },
      });
    },
  );

  it('preserves Drupal base paths in the endpoint URL', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(Response.json(page));
    const { server } = makeServer(fetchImpl as unknown as typeof fetch, {
      baseUrl: 'https://drupal.example/cms',
    });

    await server.fetchPage('/example');

    expect(fetchImpl).toHaveBeenCalledWith(
      new URL(
        'https://drupal.example/cms/canvas/content-api?requestUri=%2Fexample',
      ),
      expect.any(Object),
    );
  });

  it.each([
    ['language', '_canvas_language', 'en', 'pl'],
    ['viewMode', '_canvas_viewMode', 'full', 'teaser'],
    ['pageVariant', '_canvas_pageVariant', 'variant_a', 'variant_b'],
  ])(
    'keeps %s local when two tabs share authentication',
    async (field, query, first, second) => {
      const fetchImpl = vi.fn(async (input: string | URL | Request) => {
        const url = new URL(input instanceof Request ? input.url : input);
        if (url.pathname === '/oauth/token') return tokenResponse();
        return Response.json({
          ...page,
          head: { title: url.searchParams.get(field) ?? 'default' },
        });
      });
      const harness = makeAdapter();
      const makeTab = (value: string) => {
        const path = `/example?filter=a%20b&${query}=${value}`;
        const claims = {
          ...validClaims,
          path,
          previewContext: { [field]: value },
        };
        const adapter = {
          ...harness.adapter,
          getRequestUrl: async () => `https://app.example${path}`,
        };
        return {
          path,
          claims,
          server: createDraftServer({ adapter, config: CONFIG, fetchImpl }),
        };
      };
      const a = makeTab(first);
      const b = makeTab(second);
      const activate = async (tab: typeof a) => {
        const response = await tab.server.enableDraftMode(
          new Request(
            `https://app.example/api/draft?assertion=${buildAssertion(tab.claims)}`,
          ),
        );
        expect(response.headers.get('Location')).toBe(tab.path);
      };
      const render = async (tab: typeof a, expected: string) => {
        // Existing apps may supply only their route pathname to fetchPage().
        expect(await tab.server.fetchPage('/example')).toMatchObject({
          head: { title: expected },
        });
      };
      await activate(a);
      await render(a, first);
      await activate(b);
      await render(b, second);
      await render(a, first);
      for (const tab of [a, b]) {
        const response = await tab.server.renewDraftSession(
          new Request('https://app.example/api/draft/renew', {
            method: 'POST',
            body: JSON.stringify({ assertion: buildAssertion(tab.claims) }),
          }),
        );
        expect(response.status).toBe(200);
        await render(a, first);
        await render(b, second);
      }
      await activate(a);
      await render(b, second);
      const session = JSON.parse(
        harness.cookies.get(DRAFT_DATA_COOKIE_NAME)!.value,
      );
      expect(session).not.toHaveProperty('previewContext');
      expect(session.path).toBe('/example?filter=a%20b');
    },
  );

  it('keeps saved-only rendering local to each tab sharing a draft cookie', async () => {
    let autoSavedTitle = 'Auto-saved heading';
    const fetchImpl = vi.fn(async (input: string | URL | Request) => {
      const url = new URL(input instanceof Request ? input.url : input);
      if (url.pathname === '/oauth/token') {
        return tokenResponse();
      }
      return Response.json({
        ...page,
        head: {
          title:
            url.searchParams.get('excludeAutoSave') === 'true'
              ? 'Saved heading'
              : autoSavedTitle,
        },
      });
    });
    const { server: editor, adapter } = makeServer(fetchImpl);
    // Both tabs send the same cookies, but retain their own document URLs.
    const canonical = createDraftServer({ adapter, config: CONFIG, fetchImpl });
    const editorClaims = {
      ...validClaims,
      path: '/example',
      previewContext: {},
    };
    const savedClaims = {
      ...editorClaims,
      path: '/example?_canvas_excludeAutoSave=true',
      // An older host may still send this claim. It must not become session policy.
      previewContext: { excludeAutoSave: true },
    };
    const activate = async (
      server: typeof editor,
      claims: typeof editorClaims,
    ) => {
      const response = await server.enableDraftMode(
        new Request(
          `https://app.example/api/draft?assertion=${buildAssertion(claims)}`,
        ),
      );
      expect(response.status).toBe(307);
      return response.headers.get('Location')!;
    };
    const editorPath = await activate(editor, editorClaims);
    const expectTitle = async (
      server: typeof editor,
      path: string,
      title: string,
    ) => {
      expect(await server.fetchPage(path)).toMatchObject({ head: { title } });
    };
    await expectTitle(editor, editorPath, autoSavedTitle);
    const canonicalPath = await activate(canonical, savedClaims);
    await expectTitle(canonical, canonicalPath, 'Saved heading');
    await expectTitle(editor, editorPath, autoSavedTitle);

    autoSavedTitle = 'Another auto-saved heading';
    // Refresh both tabs after an edit, then after either tab renews the shared token.
    for (const claims of [null, editorClaims, savedClaims]) {
      if (claims) {
        const response = await editor.renewDraftSession(
          new Request('https://app.example/api/draft/renew', {
            method: 'POST',
            body: JSON.stringify({ assertion: buildAssertion(claims) }),
          }),
        );
        expect(response.status).toBe(200);
      }
      await expectTitle(editor, editorPath, autoSavedTitle);
      await expectTitle(canonical, canonicalPath, 'Saved heading');
    }
    // Recovery reactivates either host without changing the other tab's policy.
    await activate(editor, editorClaims);
    await expectTitle(canonical, canonicalPath, 'Saved heading');
    await activate(canonical, savedClaims);
    await expectTitle(editor, editorPath, autoSavedTitle);
  });

  it.each([
    {
      context: undefined,
      expected: {
        language: 'fr',
        viewMode: 'teaser',
        pageVariant: 'url',
        excludeAutoSave: 'true',
      },
    },
    { context: {}, expected: {} },
    { context: { excludeAutoSave: false }, expected: {} },
    { context: { viewMode: 'full' }, expected: { viewMode: 'full' } },
    {
      context: {
        language: 'de',
        viewMode: 'full',
        pageVariant: 'explicit',
        excludeAutoSave: true,
      },
      expected: {
        language: 'de',
        viewMode: 'full',
        pageVariant: 'explicit',
        excludeAutoSave: 'true',
      },
    },
  ])(
    'resolves explicit page context $context only with a live session',
    async ({ context, expected }) => {
      for (const session of ['live', 'expired', 'public']) {
        const fetchImpl = vi.fn().mockResolvedValue(Response.json(page));
        const harness = makeAdapter();
        harness.adapter.getRequestUrl = vi
          .fn()
          .mockResolvedValue(
            'https://app.example/example?_canvas_language=en&_canvas_pageVariant=url&_canvas_excludeAutoSave=true',
          );
        if (session !== 'public') {
          harness.seedSession(
            liveDraftData({
              tokenExpiresAt: Date.now() + (session === 'live' ? 60_000 : -1),
            }),
          );
        }
        const server = createDraftServer({
          adapter: harness.adapter,
          config: CONFIG,
          fetchImpl,
        });

        await server.fetchPage(
          '/example?page=2&_canvas_language=fr&_canvas_viewMode=teaser',
          context,
        );

        const [url, init] = fetchImpl.mock.calls[0];
        expect(Object.fromEntries(url.searchParams)).toEqual({
          requestUri: '/example?page=2',
          ...(session === 'live' ? expected : {}),
        });
        expect(init.headers.Authorization).toBe(
          session === 'live' ? 'Bearer old-token' : undefined,
        );
        expect(harness.adapter.getRequestUrl).toHaveBeenCalledTimes(
          context === undefined ? 1 : 0,
        );
      }
    },
  );

  it('marks a draft component tree as editor-renderable', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(Response.json(page));
    const { server, seedSession } = makeServer(
      fetchImpl as unknown as typeof fetch,
    );
    seedSession(
      liveDraftData({
        previewContext: {
          language: 'fr',
          viewMode: 'teaser',
          pageVariant: 'alternate',
        },
      }),
    );

    await expect(
      server.fetchPage(
        '/example?_canvas_language=fr&_canvas_viewMode=teaser&_canvas_pageVariant=alternate',
      ),
    ).resolves.toEqual({
      ...page,
      content: { element: 'canvas-page', canvasDraftMode: true },
    });
    expect(fetchImpl).toHaveBeenCalledWith(
      new URL(
        'https://drupal.example/canvas/content-api?requestUri=%2Fexample&language=fr&viewMode=teaser&pageVariant=alternate',
      ),
      expect.objectContaining({
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer old-token',
        },
      }),
    );
  });

  it.each([
    undefined,
    '/api/canvas/component-preview?_canvas_language=pl&_canvas_viewMode=teaser&_canvas_pageVariant=alternate&_canvas_excludeAutoSave=true',
  ])(
    'fetches a component using only request language from %s',
    async (previewUri) => {
      const fetchImpl = vi.fn().mockResolvedValue(Response.json(page));
      const { server, seedSession } = makeServer(
        fetchImpl as unknown as typeof fetch,
      );
      seedSession(
        liveDraftData({
          path: '/example?language=fr&_canvas_excludeAutoSave=true',
          previewContext: {
            pageVariant: 'alternate',
          },
        }),
      );

      await server.fetchComponentPreview('js.example', previewUri);

      expect(fetchImpl).toHaveBeenCalledWith(
        new URL(
          `https://drupal.example/canvas/content-api?requestUri=%2F&componentId=js.example${previewUri ? '&language=pl' : ''}`,
        ),
        expect.any(Object),
      );
      expect(await server.getDraftData()).toMatchObject({
        path: '/example?language=fr',
      });
    },
  );

  it.each([
    [undefined, true],
    [true, true],
    [false, true],
    [true, false],
  ] as const)(
    'applies excludeAutoSave=%s only to live page sessions (%s)',
    async (excludeAutoSave, live) => {
      const fetchImpl = vi.fn().mockResolvedValue(Response.json(page));
      const { server, seedSession } = makeServer(fetchImpl);
      seedSession(
        liveDraftData({
          tokenExpiresAt: Date.now() + (live ? 60_000 : -1),
          // A cookie written by an older SDK must not override this request.
          previewContext: {
            language: 'en',
            ...{ excludeAutoSave: !excludeAutoSave },
          },
        }),
      );

      const query =
        excludeAutoSave === undefined
          ? ''
          : `?_canvas_excludeAutoSave=${excludeAutoSave}`;
      await server.fetchPage(`/example${query}`);

      const [url, init] = fetchImpl.mock.calls[0];
      expect(url.searchParams.get('requestUri')).toBe('/example');
      expect(url.searchParams.get('excludeAutoSave')).toBe(
        live && excludeAutoSave === true ? 'true' : null,
      );
      expect(init.headers.Authorization).toBe(
        live ? 'Bearer old-token' : undefined,
      );
    },
  );

  it('keeps an expired draft session anonymous and marker-free', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(Response.json(page));
    const { server, seedSession } = makeServer(
      fetchImpl as unknown as typeof fetch,
    );
    seedSession(
      liveDraftData({
        tokenExpiresAt: Date.now() - 1,
        previewContext: { language: 'fr' },
      }),
    );

    await expect(server.fetchPage('/example')).resolves.toEqual(page);
    expect(fetchImpl).toHaveBeenCalledWith(
      new URL(
        'https://drupal.example/canvas/content-api?requestUri=%2Fexample',
      ),
      expect.objectContaining({
        headers: { Accept: 'application/json' },
      }),
    );
  });

  it('makes empty draft content editor-renderable', async () => {
    const emptyPage = {
      ...page,
      content: null,
    };
    const fetchImpl = vi.fn().mockResolvedValue(Response.json(emptyPage));
    const { server, seedSession } = makeServer(
      fetchImpl as unknown as typeof fetch,
    );
    seedSession(liveDraftData());

    await expect(server.fetchPage('/example')).resolves.toEqual({
      ...emptyPage,
      content: {
        element: 'renderless-container',
        canvasDraftMode: true,
      },
    });
  });

  it('keeps unmanaged draft content empty', async () => {
    const unmanagedPage = {
      ...page,
      content: null,
      route: { ...page.route, managedByCanvas: false },
    };
    const fetchImpl = vi.fn().mockResolvedValue(Response.json(unmanagedPage));
    const { server, seedSession } = makeServer(
      fetchImpl as unknown as typeof fetch,
    );
    seedSession(liveDraftData());

    await expect(server.fetchPage('/example')).resolves.toEqual(unmanagedPage);
  });
});

describe('fetchEntity', () => {
  const entity = {
    content: { element: 'js-article-card' },
    managedByCanvas: true,
    entity: {
      entityType: 'node',
      bundle: 'article',
      id: '2',
      uuid: 'node-uuid',
      langcode: 'en',
    },
  };

  it('fetches one public entity in a specific view mode', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(Response.json(entity));
    const { server } = makeServer(fetchImpl as unknown as typeof fetch);

    await expect(
      server.fetchEntity({ type: 'node', id: '2', viewMode: 'teaser' }),
    ).resolves.toEqual(entity);
    expect(fetchImpl).toHaveBeenCalledWith(
      new URL(
        'https://drupal.example/canvas/content-api/entity?type=node&id=2&viewMode=teaser',
      ),
      expect.objectContaining({
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      }),
    );
  });

  it('marks a managed draft entity render as editor-renderable', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(Response.json(entity));
    const { server, seedSession } = makeServer(
      fetchImpl as unknown as typeof fetch,
    );
    seedSession(liveDraftData());

    await expect(
      server.fetchEntity({ type: 'node', id: '2' }),
    ).resolves.toEqual({
      ...entity,
      content: { element: 'js-article-card', canvasDraftMode: true },
    });
  });

  it('returns unmanaged draft content unchanged', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(
        Response.json({ ...entity, content: null, managedByCanvas: false }),
      );
    const { server, seedSession } = makeServer(
      fetchImpl as unknown as typeof fetch,
    );
    seedSession(liveDraftData());

    await expect(
      server.fetchEntity({ type: 'node', id: '2' }),
    ).resolves.toEqual({ ...entity, content: null, managedByCanvas: false });
  });

  it.each([
    [undefined, true],
    [true, true],
    [false, true],
    [true, false],
  ] as const)(
    'applies excludeAutoSave=%s only to live entity sessions (%s)',
    async (excludeAutoSave, live) => {
      const fetchImpl = vi.fn().mockResolvedValue(Response.json(entity));
      const { server, seedSession } = makeServer(fetchImpl);
      seedSession(
        liveDraftData({
          tokenExpiresAt: Date.now() + (live ? 60_000 : -1),
          // A cookie written by an older SDK must not override this request.
          previewContext: {
            language: 'en',
            ...{ excludeAutoSave: !excludeAutoSave },
          },
        }),
      );

      await server.fetchEntity({ type: 'node', id: '2', excludeAutoSave });

      const [url, init] = fetchImpl.mock.calls[0];
      expect(url.searchParams.get('excludeAutoSave')).toBe(
        live && excludeAutoSave === true ? 'true' : null,
      );
      expect(init.headers.Authorization).toBe(
        live ? 'Bearer old-token' : undefined,
      );
    },
  );
});
