import { describe, expect, it, vi } from 'vitest';

import { verifyAssertionByRedemption } from './verify-assertion';

const CONFIG = {
  baseUrl: 'https://drupal.example',
};

describe('verifyAssertionByRedemption', () => {
  it('verifies a redeemable assertion and discards the token', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(
      Response.json({
        token_type: 'Bearer',
        expires_in: 900,
        access_token: 'secret-token',
      }),
    );

    const result = await verifyAssertionByRedemption(
      'assertion-jwt',
      CONFIG,
      fetchImpl as unknown as typeof fetch,
    );

    expect(result).toEqual({ ok: true });
    // No token material may leave the verifier.
    expect(JSON.stringify(result)).not.toContain('secret-token');

    const [url, init] = fetchImpl.mock.calls[0];
    expect(url).toBe('https://drupal.example/oauth/token');
    expect(init.method).toBe('POST');
    expect(init.cache).toBe('no-store');
    const body = new URLSearchParams(init.body);
    expect(body.get('grant_type')).toBe(
      'urn:ietf:params:oauth:grant-type:jwt-bearer',
    );
    expect(body.get('assertion')).toBe('assertion-jwt');
    expect(body.get('client_id')).toBe('canvas_headless');
  });

  it('maps an OAuth refusal to 401 with the upstream detail', async () => {
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

    const result = await verifyAssertionByRedemption(
      'replayed',
      CONFIG,
      fetchImpl as unknown as typeof fetch,
    );

    expect(result).toEqual({
      ok: false,
      status: 401,
      message: 'The assertion was already used. Mint a fresh one.',
    });
  });

  it('maps an upstream 401 to 401', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(Response.json({}, { status: 401 }));
    const result = await verifyAssertionByRedemption(
      'bad',
      CONFIG,
      fetchImpl as unknown as typeof fetch,
    );
    expect(result).toMatchObject({ ok: false, status: 401 });
  });

  it('passes an upstream 403 through', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(
        Response.json({ error_description: 'Forbidden.' }, { status: 403 }),
      );
    const result = await verifyAssertionByRedemption(
      'forbidden',
      CONFIG,
      fetchImpl as unknown as typeof fetch,
    );
    expect(result).toEqual({ ok: false, status: 403, message: 'Forbidden.' });
  });

  it('maps an unexpected upstream status to 502', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(Response.json({}, { status: 500 }));
    const result = await verifyAssertionByRedemption(
      'x',
      CONFIG,
      fetchImpl as unknown as typeof fetch,
    );
    expect(result).toMatchObject({ ok: false, status: 502 });
  });

  it('answers 502 when Drupal is unreachable', async () => {
    const fetchImpl = vi.fn().mockRejectedValue(new Error('refused'));
    const result = await verifyAssertionByRedemption(
      'x',
      CONFIG,
      fetchImpl as unknown as typeof fetch,
    );
    expect(result).toMatchObject({ ok: false, status: 502 });
  });
});
