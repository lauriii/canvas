import { describe, expect, it } from 'vitest';

import { codeChallenge, generateCodeVerifier } from './pkce';

describe('generateCodeVerifier', () => {
  it('produces RFC 7636 verifiers, unique per call', () => {
    const verifier = generateCodeVerifier();
    // 32 random bytes → 43 base64url characters, the RFC minimum length.
    expect(verifier).toMatch(/^[A-Za-z0-9_-]{43}$/);
    expect(generateCodeVerifier()).not.toBe(verifier);
  });
});

describe('codeChallenge', () => {
  it('computes the S256 challenge (RFC 7636 appendix B vector)', async () => {
    expect(
      await codeChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'),
    ).toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
  });
});
