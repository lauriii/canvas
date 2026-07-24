import { describe, expect, it, vi } from 'vitest';

import { DRAFT_DATA_COOKIE_NAME } from '../constants';
import { serializeDraftData } from '../draft-data';
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

function liveDraftData(overrides: Partial<DraftData> = {}): DraftData {
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
        value: serializeDraftData(draftData),
        httpOnly: true,
        path: '/',
        sameSite: 'none',
        secure: true,
        partitioned: true,
      });
    },
  };
}

function makeServer(fetchImpl: typeof fetch) {
  const harness = makeAdapter();
  const server = createDraftServer({
    adapter: harness.adapter,
    config: CONFIG,
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

  it('stores the session cross-site and redirects to the signed path', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(tokenResponse());
    const { server, cookies, getFlag } = makeServer(
      fetchImpl as unknown as typeof fetch,
    );

    const assertion = buildAssertion(validClaims);
    const response = await server.enableDraftMode(
      new Request(
        `https://app.example/api/draft?assertion=${encodeURIComponent(assertion)}`,
      ),
    );

    expect(response.status).toBe(307);
    expect(response.headers.get('Location')).toBe('/node/1');
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
    expect(JSON.parse(dataCookie!.value)).toMatchObject({ path: '/node/1' });
  });

  it('continues into a live session when the assertion is dead', async () => {
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
      new Request('https://app.example/api/draft?assertion=dead'),
    );

    expect(response.status).toBe(307);
    expect(response.headers.get('Location')).toBe('/node/9');
  });

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

  it('renews the session and answers the new expiry as JSON', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(tokenResponse());
    const { server, seedSession, cookies } = makeServer(
      fetchImpl as unknown as typeof fetch,
    );
    seedSession(liveDraftData({ sub: '42' }));

    const response = await server.renewDraftSession(
      renewRequest({ assertion: buildAssertion(validClaims) }),
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
  });
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

describe('fetchPage', () => {
  const page = {
    title: 'Example page',
    content_format: 'json' as const,
    content: { element: 'canvas-page' },
  };

  it('keeps a public component tree marker-free', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(Response.json(page));
    const { server } = makeServer(fetchImpl as unknown as typeof fetch);

    await expect(server.fetchPage('/example')).resolves.toEqual(page);
  });

  it('marks a draft component tree as editor-renderable', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(Response.json(page));
    const { server, seedSession } = makeServer(
      fetchImpl as unknown as typeof fetch,
    );
    seedSession(liveDraftData());

    await expect(server.fetchPage('/example')).resolves.toEqual({
      ...page,
      content: { element: 'canvas-page', canvasDraftMode: true },
    });
  });
});
