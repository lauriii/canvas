import { describe, expect, it } from 'vitest';

import { findInChanges } from '@/utils/function-utils';

import type { PendingChanges } from '@/services/pendingChangesApi';

const change = (id: string, langcode: string | null) =>
  ({
    entity_type: 'canvas_page',
    entity_id: id,
    langcode,
  }) as unknown as PendingChanges[string];

describe('findInChanges', () => {
  const changes = {
    'canvas_page:8:en': change('8', 'en'),
    'canvas_page:8:de': change('8', 'de'),
    'canvas_page:9': change('9', null),
  } as unknown as PendingChanges;

  it('finds a change of an entity', () => {
    expect(findInChanges(changes, '8', 'canvas_page')).toBe(true);
    expect(findInChanges(changes, '10', 'canvas_page')).toBe(false);
  });

  it('finds a change of a translation of an entity', () => {
    // A translation with a custom layout has changes of its own.
    expect(
      findInChanges(
        changes,
        '8',
        'canvas_page',
        (langcode) => langcode === 'de',
      ),
    ).toBe(true);
    expect(
      findInChanges(
        changes,
        '8',
        'canvas_page',
        (langcode) => langcode === 'fr',
      ),
    ).toBe(false);
    expect(
      findInChanges(changes, '9', 'canvas_page', (langcode) => !langcode),
    ).toBe(true);
  });
});
