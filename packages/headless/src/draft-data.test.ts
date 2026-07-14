import { describe, expect, it } from 'vitest';

import {
  EXPIRY_SLACK_MS,
  isDraftSessionExpired,
  parseDraftData,
  serializeDraftData,
} from './draft-data';

import type { DraftData } from './draft-data';

const validDraftData: DraftData = {
  path: '/node/1',
  resourceVersion: 'rel:working-copy',
  sub: '42',
  renewUrl: 'https://drupal.example/canvas-headless/renew',
  accessToken: 'token-value',
  tokenType: 'Bearer',
  tokenExpiresAt: 1_800_000_000_000,
  codeVerifier: 'stored-verifier',
};

describe('parseDraftData', () => {
  it('round-trips serialized draft data', () => {
    expect(parseDraftData(serializeDraftData(validDraftData))).toEqual(
      validDraftData,
    );
  });

  it.each([null, undefined, ''])('returns null for %s', (value) => {
    expect(parseDraftData(value)).toBeNull();
  });

  it('returns null for non-JSON input', () => {
    expect(parseDraftData('not json')).toBeNull();
  });

  it.each(Object.keys(validDraftData))(
    'returns null when %s is missing',
    (field) => {
      const data: Record<string, unknown> = { ...validDraftData };
      delete data[field];
      expect(parseDraftData(JSON.stringify(data))).toBeNull();
    },
  );

  it.each(Object.keys(validDraftData))(
    'returns null when %s has the wrong type',
    (field) => {
      const data: Record<string, unknown> = { ...validDraftData };
      // Every field is a string except tokenExpiresAt, which is a number.
      data[field] = field === 'tokenExpiresAt' ? 'soon' : 12345;
      expect(parseDraftData(JSON.stringify(data))).toBeNull();
    },
  );
});

describe('isDraftSessionExpired', () => {
  const expiresAt = validDraftData.tokenExpiresAt;

  it('is live strictly before the slack boundary', () => {
    expect(
      isDraftSessionExpired(validDraftData, expiresAt - EXPIRY_SLACK_MS - 1),
    ).toBe(false);
  });

  it('is expired at the slack boundary', () => {
    expect(
      isDraftSessionExpired(validDraftData, expiresAt - EXPIRY_SLACK_MS),
    ).toBe(true);
  });

  it('is expired past the token expiry itself', () => {
    expect(isDraftSessionExpired(validDraftData, expiresAt + 1)).toBe(true);
  });
});
