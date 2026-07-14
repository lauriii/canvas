import { describe, expect, it } from 'vitest';

import { resolveCorsHeaders } from './cors';

const ALLOWED = ['https://drupal.example', 'https://staging.example'];

describe('resolveCorsHeaders', () => {
  it('admits a server-to-server request without an Origin header', () => {
    expect(resolveCorsHeaders(null, ALLOWED)).toEqual({
      allowed: true,
      headers: {},
    });
  });

  it('echoes an allowlisted origin exactly, never a wildcard', () => {
    const decision = resolveCorsHeaders('https://drupal.example', ALLOWED);
    expect(decision.allowed).toBe(true);
    expect(decision.headers['Access-Control-Allow-Origin']).toBe(
      'https://drupal.example',
    );
    expect(decision.headers.Vary).toBe('Origin');
    expect(decision.headers['Access-Control-Allow-Headers']).toBe(
      'Authorization',
    );
    expect(
      Object.values(decision.headers).some((value) => value.includes('*')),
    ).toBe(false);
    expect(decision.headers).not.toHaveProperty(
      'Access-Control-Allow-Credentials',
    );
  });

  it('refuses an origin outside the allowlist', () => {
    expect(resolveCorsHeaders('https://evil.example', ALLOWED)).toEqual({
      allowed: false,
      headers: {},
    });
  });

  it('matches origins exactly, not by prefix', () => {
    expect(
      resolveCorsHeaders('https://drupal.example.evil.example', ALLOWED)
        .allowed,
    ).toBe(false);
  });

  it('never admits a browser origin with an empty allowlist', () => {
    expect(resolveCorsHeaders('https://drupal.example', []).allowed).toBe(
      false,
    );
  });
});
