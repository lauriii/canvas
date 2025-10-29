import { describe, expect, it } from 'vitest';

import { extractAutoSavesRequestUrl } from '@/utils/autoSaves';

describe('extractAutoSavesRequestUrl', () => {
  it('extracts the canonical URL without query/hash', () => {
    expect(
      extractAutoSavesRequestUrl(
        'https://host/canvas/api/v0/layout/canvas_page/1?x=1#y',
      ),
    ).toBe('canvas/api/v0/layout/canvas_page/1');
    expect(
      extractAutoSavesRequestUrl(
        'https://host/canvas/api/v0/layout/canvas_page/2#hash',
      ),
    ).toBe('canvas/api/v0/layout/canvas_page/2');
    expect(
      extractAutoSavesRequestUrl(
        'https://host/canvas/api/v0/layout/canvas_page/3?x=1',
      ),
    ).toBe('canvas/api/v0/layout/canvas_page/3');
    expect(
      extractAutoSavesRequestUrl(
        'https://host/canvas/api/v0/layout/canvas_page/4',
      ),
    ).toBe('canvas/api/v0/layout/canvas_page/4');
  });

  it('returns undefined if canvas/api is not present', () => {
    expect(
      extractAutoSavesRequestUrl('https://host/other/api/foo'),
    ).toBeUndefined();
    expect(extractAutoSavesRequestUrl(undefined)).toBeUndefined();
    expect(extractAutoSavesRequestUrl('')).toBeUndefined();
  });
});
