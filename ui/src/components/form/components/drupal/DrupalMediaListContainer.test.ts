import { describe, expect, it } from 'vitest';

import { reorderStoredItems } from './DrupalMediaListContainer';

describe('reorderStoredItems', () => {
  it('moves stored items, with their focal points, to the new order', () => {
    expect(
      reorderStoredItems(
        [
          { target_id: 1, focal_point: { x: 10, y: 20 } },
          { target_id: '2', focal_point: { x: 80, y: 30 } },
        ],
        ['2', '1'],
      ),
    ).toEqual([
      { target_id: '2', focal_point: { x: 80, y: 30 } },
      { target_id: 1, focal_point: { x: 10, y: 20 } },
    ]);
  });

  it('keeps duplicates apart and falls back to the bare ID', () => {
    expect(
      reorderStoredItems(
        [{ target_id: '1', focal_point: { x: 1, y: 1 } }, { target_id: '1' }],
        ['1', '3', '1'],
      ),
    ).toEqual([
      { target_id: '1', focal_point: { x: 1, y: 1 } },
      '3',
      { target_id: '1' },
    ]);
    expect(reorderStoredItems(undefined, ['4'])).toEqual(['4']);
  });
});
