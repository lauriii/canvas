import { describe, expect, it } from 'vitest';

import {
  isTopLevelPageSpecPath,
  pageSlugFromTopLevelSpecPath,
} from './page-spec-path';

describe('isTopLevelPageSpecPath', () => {
  it('matches pages/one-segment.json', () => {
    expect(isTopLevelPageSpecPath('pages/home.json')).toBe(true);
    expect(isTopLevelPageSpecPath('foo/pages/about.json')).toBe(true);
  });

  it('rejects nested paths and non-json', () => {
    expect(isTopLevelPageSpecPath('pages/nested/foo.json')).toBe(false);
    expect(isTopLevelPageSpecPath('pages/home.json.bak')).toBe(false);
  });
});

describe('pageSlugFromTopLevelSpecPath', () => {
  it('returns slug basename', () => {
    expect(pageSlugFromTopLevelSpecPath('pages/home.json')).toBe('home');
  });
});
