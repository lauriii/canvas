import { describe, expect, it } from 'vitest';

import { getContentEntityReferenceTarget } from './content-entity-reference';

describe('getContentEntityReferenceTarget', () => {
  it('derives a bundled target from the first entity field expression', () => {
    expect(
      getContentEntityReferenceTarget(['ℹ︎␜entity:node:article␝title␞␟value']),
    ).toEqual({ entityTypeId: 'node', bundle: 'article' });
  });

  it('derives an unbundled target without inventing a bundle', () => {
    expect(
      getContentEntityReferenceTarget(['ℹ︎␜entity:user␝name␞␟value']),
    ).toEqual({ entityTypeId: 'user', bundle: null });
  });

  it.each([undefined, [], ['not-an-entity-field-expression']])(
    'returns null when the target cannot be derived from %j',
    (expressions) => {
      expect(getContentEntityReferenceTarget(expressions)).toBeNull();
    },
  );
});
