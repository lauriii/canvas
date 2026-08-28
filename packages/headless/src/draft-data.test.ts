import { describe, expect, it } from 'vitest';

import {
  EXPIRY_SLACK_MS,
  getDraftEditorOrigin,
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

describe('getDraftEditorOrigin', () => {
  it('returns the exact HTTP(S) origin of the signed renewal URL', () => {
    expect(getDraftEditorOrigin(validDraftData)).toBe('https://drupal.example');
    expect(
      getDraftEditorOrigin({
        renewUrl: 'http://localhost:8080/canvas-headless/renew',
      }),
    ).toBe('http://localhost:8080');
  });

  it.each([
    null,
    { renewUrl: 'not a URL' },
    { renewUrl: 'ftp://drupal.example/renew' },
    { renewUrl: 'https://user:secret@drupal.example/renew' },
  ])('returns null for an unusable renewal URL', (draftData) => {
    expect(getDraftEditorOrigin(draftData)).toBeNull();
  });
});

describe('parseDraftData', () => {
  it('round-trips serialized draft data', () => {
    expect(parseDraftData(serializeDraftData(validDraftData))).toEqual(
      validDraftData,
    );
  });

  it('round-trips optional editor preview context', () => {
    const draftData = {
      ...validDraftData,
      previewContext: {
        viewMode: 'teaser',
        pageVariant: 'alternate',
      },
    };
    expect(parseDraftData(serializeDraftData(draftData))).toEqual(draftData);
  });

  it('rejects invalid editor preview context', () => {
    expect(
      parseDraftData(
        JSON.stringify({
          ...validDraftData,
          previewContext: { pageVariant: 42 },
        }),
      ),
    ).toBeNull();
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
