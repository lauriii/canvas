import { describe, expect, it } from 'vitest';

import { decodeAssertionClaims } from './assertion';

function buildAssertion(claims: unknown): string {
  const encode = (value: unknown) =>
    Buffer.from(JSON.stringify(value)).toString('base64url');
  return `${encode({ alg: 'RS256', typ: 'JWT' })}.${encode(claims)}.signature`;
}

describe('decodeAssertionClaims', () => {
  it('decodes the claim set of a well-formed assertion', () => {
    const claims = { sub: '42', path: '/node/1' };
    expect(decodeAssertionClaims(buildAssertion(claims))).toEqual(claims);
  });

  // Base64url payloads whose lengths need zero, one, and two padding
  // characters, exercising the atob() padding logic.
  it.each(['a', 'ab', 'abc', 'abcd'])(
    'decodes payloads of every padding length (%s)',
    (value) => {
      const claims = { value };
      expect(decodeAssertionClaims(buildAssertion(claims))).toEqual(claims);
    },
  );

  it('decodes payloads containing base64url-specific characters', () => {
    // '?' and '~' encode to '/' and '+' in standard base64, which base64url
    // replaces with '_' and '-'.
    const claims = { data: '????~~~~', nested: { deep: '>>>???' } };
    const assertion = buildAssertion(claims);
    expect(assertion.split('.')[1]).toMatch(/[-_]/);
    expect(decodeAssertionClaims(assertion)).toEqual(claims);
  });

  it('returns null for a token without three parts', () => {
    expect(decodeAssertionClaims('one.two')).toBeNull();
    expect(decodeAssertionClaims('one.two.three.four')).toBeNull();
    expect(decodeAssertionClaims('')).toBeNull();
  });

  it('returns null for a payload that is not JSON', () => {
    const payload = Buffer.from('not json').toString('base64url');
    expect(decodeAssertionClaims(`h.${payload}.s`)).toBeNull();
  });

  it('returns null for a payload that is not an object', () => {
    const payload = Buffer.from('"a string"').toString('base64url');
    expect(decodeAssertionClaims(`h.${payload}.s`)).toBeNull();
  });

  it('returns null for a payload that is not base64', () => {
    expect(decodeAssertionClaims('h.!!!not-base64!!!.s')).toBeNull();
  });
});
