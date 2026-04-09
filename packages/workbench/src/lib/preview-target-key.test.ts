import { describe, expect, it } from 'vitest';

import { getPreviewTargetKey } from './preview-target-key';

describe('getPreviewTargetKey', () => {
  it('combines render type and id', () => {
    expect(getPreviewTargetKey('page', 'home')).toBe('page:home');
    expect(getPreviewTargetKey('component', 'abc')).toBe('component:abc');
  });

  it('treats distinct ids as distinct keys', () => {
    expect(getPreviewTargetKey('page', 'a')).not.toBe(
      getPreviewTargetKey('page', 'b'),
    );
    expect(getPreviewTargetKey('page', 'x')).not.toBe(
      getPreviewTargetKey('component', 'x'),
    );
  });

  it('preserves mock-style render ids with colons', () => {
    expect(getPreviewTargetKey('component', 'card:mock-a')).toBe(
      'component:card:mock-a',
    );
  });
});
