import { createHash } from 'crypto';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest';

import {
  deriveCodeChallenge,
  discoverAuth,
  exchangeCode,
  generateCodeVerifier,
  generateState,
} from './auth';

const server = setupServer();

beforeAll(() => server.listen());
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

const SITE = 'https://site.example.com';
const AUTH_SERVER = 'https://auth.example.com';

function useDiscoveryHandlers(overrides?: {
  protectedResource?: Parameters<typeof http.get>[1];
  oidcConfig?: Parameters<typeof http.get>[1];
}) {
  server.use(
    http.get(
      `${SITE}/.well-known/oauth-protected-resource`,
      overrides?.protectedResource ??
        (() =>
          HttpResponse.json(
            {
              authorization_servers: [AUTH_SERVER],
              scopes_supported: ['canvas:js_component', 'canvas:asset_library'],
            },
            { headers: { 'x-consumer-id': 'discovered-client-id' } },
          )),
    ),
    http.get(
      `${AUTH_SERVER}/.well-known/openid-configuration`,
      overrides?.oidcConfig ??
        (() =>
          HttpResponse.json({
            authorization_endpoint: `${AUTH_SERVER}/oauth2/authorize`,
            token_endpoint: `${AUTH_SERVER}/oauth2/token`,
          })),
    ),
  );
}

describe('generateCodeVerifier', () => {
  it('produces a string of 43 to 96 URL-safe characters', () => {
    const verifier = generateCodeVerifier();
    expect(verifier).toMatch(/^[A-Za-z0-9\-_]{43,96}$/);
  });

  it('produces a different value on each call', () => {
    expect(generateCodeVerifier()).not.toBe(generateCodeVerifier());
  });
});

describe('deriveCodeChallenge', () => {
  it('produces the SHA-256 base64url of the verifier', () => {
    const verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    const expected = createHash('sha256').update(verifier).digest('base64url');
    expect(deriveCodeChallenge(verifier)).toBe(expected);
  });

  it('is deterministic for the same input', () => {
    const verifier = 'some-fixed-verifier';
    expect(deriveCodeChallenge(verifier)).toBe(deriveCodeChallenge(verifier));
  });
});

describe('generateState', () => {
  it('produces a 32-character hex string', () => {
    expect(generateState()).toMatch(/^[0-9a-f]{32}$/);
  });

  it('produces a different value on each call', () => {
    expect(generateState()).not.toBe(generateState());
  });
});

describe('discoverAuth', () => {
  it('returns endpoints, clientId, and scopesSupported from server metadata', async () => {
    useDiscoveryHandlers();
    const result = await discoverAuth(SITE);
    expect(result).toEqual({
      authorizationEndpoint: `${AUTH_SERVER}/oauth2/authorize`,
      tokenEndpoint: `${AUTH_SERVER}/oauth2/token`,
      clientId: 'discovered-client-id',
      scopesSupported: ['canvas:js_component', 'canvas:asset_library'],
    });
  });

  it('uses X-Consumer-ID header as clientId when no override given', async () => {
    useDiscoveryHandlers();
    const result = await discoverAuth(SITE);
    expect(result.clientId).toBe('discovered-client-id');
  });

  it('uses clientIdOverride instead of X-Consumer-ID when provided', async () => {
    useDiscoveryHandlers();
    const result = await discoverAuth(SITE, 'my-override-client');
    expect(result.clientId).toBe('my-override-client');
  });

  it('returns undefined scopesSupported when field is absent from response', async () => {
    useDiscoveryHandlers({
      protectedResource: () =>
        HttpResponse.json(
          { authorization_servers: [AUTH_SERVER] },
          { headers: { 'x-consumer-id': 'cid' } },
        ),
    });
    const result = await discoverAuth(SITE);
    expect(result.scopesSupported).toBeUndefined();
  });

  it('returns empty array when scopes_supported is empty', async () => {
    useDiscoveryHandlers({
      protectedResource: () =>
        HttpResponse.json(
          { authorization_servers: [AUTH_SERVER], scopes_supported: [] },
          { headers: { 'x-consumer-id': 'cid' } },
        ),
    });
    const result = await discoverAuth(SITE);
    expect(result.scopesSupported).toEqual([]);
  });

  it('throws when authorization_servers is missing', async () => {
    useDiscoveryHandlers({
      protectedResource: () =>
        HttpResponse.json({}, { headers: { 'x-consumer-id': 'cid' } }),
    });
    await expect(discoverAuth(SITE)).rejects.toThrow(
      'OAuth discovery response is missing authorization_servers',
    );
  });

  it('falls back to Simple OAuth endpoints on 404', async () => {
    server.use(
      http.get(`${SITE}/.well-known/oauth-protected-resource`, () =>
        HttpResponse.json({}, { status: 404 }),
      ),
    );
    const result = await discoverAuth(SITE, 'fallback-client');
    expect(result).toEqual({
      authorizationEndpoint: `${SITE}/oauth/authorize`,
      tokenEndpoint: `${SITE}/oauth/token`,
      clientId: 'fallback-client',
    });
  });

  it('throws on 404 fallback when no clientIdOverride provided', async () => {
    server.use(
      http.get(`${SITE}/.well-known/oauth-protected-resource`, () =>
        HttpResponse.json({}, { status: 404 }),
      ),
    );
    await expect(discoverAuth(SITE)).rejects.toThrow('Use --client-id');
  });

  it('throws with site URL when no network response', async () => {
    server.use(
      http.get(`${SITE}/.well-known/oauth-protected-resource`, () =>
        HttpResponse.error(),
      ),
    );
    await expect(discoverAuth(SITE)).rejects.toThrow(`Could not reach ${SITE}`);
  });

  it('includes DDEV troubleshooting hints for ddev.site URLs', async () => {
    const ddevSite = 'https://myproject.ddev.site';
    server.use(
      http.get(`${ddevSite}/.well-known/oauth-protected-resource`, () =>
        HttpResponse.error(),
      ),
    );
    await expect(discoverAuth(ddevSite)).rejects.toThrow('ddev status');
  });

  it('throws when OIDC config is missing authorization_endpoint', async () => {
    useDiscoveryHandlers({
      oidcConfig: () =>
        HttpResponse.json({ token_endpoint: `${AUTH_SERVER}/oauth2/token` }),
    });
    await expect(discoverAuth(SITE)).rejects.toThrow(
      'Ensure /.well-known/openid-configuration is available',
    );
  });
});

describe('exchangeCode', () => {
  const baseParams = {
    tokenEndpoint: `${AUTH_SERVER}/oauth2/token`,
    code: 'auth-code-123',
    redirectUri: 'http://localhost:4444/callback',
    clientId: 'client-id',
    codeVerifier: 'verifier-abc',
  };

  it('maps snake_case token response fields to camelCase', async () => {
    server.use(
      http.post(`${AUTH_SERVER}/oauth2/token`, () =>
        HttpResponse.json({
          access_token: 'access-token-value',
          refresh_token: 'refresh-token-value',
          expires_in: 3600,
        }),
      ),
    );
    const result = await exchangeCode(baseParams);
    expect(result).toEqual({
      accessToken: 'access-token-value',
      refreshToken: 'refresh-token-value',
      expiresIn: 3600,
    });
  });

  it('returns undefined refreshToken when absent from response', async () => {
    server.use(
      http.post(`${AUTH_SERVER}/oauth2/token`, () =>
        HttpResponse.json({ access_token: 'token' }),
      ),
    );
    const result = await exchangeCode(baseParams);
    expect(result.refreshToken).toBeUndefined();
  });

  it('throws with error description when server responds with HTTP 400', async () => {
    server.use(
      http.post(`${AUTH_SERVER}/oauth2/token`, () =>
        HttpResponse.json(
          {
            error: 'invalid_grant',
            error_description: 'The authorization code has expired',
          },
          { status: 400 },
        ),
      ),
    );
    await expect(exchangeCode(baseParams)).rejects.toThrow(
      'Token exchange failed: The authorization code has expired',
    );
  });
});
