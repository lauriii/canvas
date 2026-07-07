import { describe, expect, it } from 'vitest';

import {
  findConflictIndex,
  getConflictQueue,
  getConflictRouteFromEntry,
  getNextConflictEntry,
} from './conflictComparison';

import type {
  PendingChange,
  PendingChanges,
} from '@/services/pendingChangesApi';

const makeChange = (
  overrides: Partial<PendingChange> & { entity_id: string | number },
): PendingChange => ({
  owner: {
    name: 'Builder',
    avatar: null,
    uri: '/user/1',
    id: 1,
  },
  entity_type: 'canvas_page',
  data_hash: 'draft-hash',
  langcode: 'en',
  label: 'Page',
  updated: 100,
  hasConflict: true,
  conflict_id: 'revision-2',
  ...overrides,
});

describe('page conflict queue', () => {
  it('accepts status-less Page conflicts and excludes unsupported entity types', () => {
    const changes: PendingChanges = {
      'canvas_page:1:en': makeChange({ entity_id: 1, updated: 100 }),
      'canvas_page:2:en': makeChange({ entity_id: 2, updated: 300 }),
      'js_component:hero:en': makeChange({
        entity_type: 'js_component',
        entity_id: 'hero',
        updated: 400,
      }),
    };

    expect(getConflictQueue(changes).map(({ pointer }) => pointer)).toEqual([
      'canvas_page:2:en',
      'canvas_page:1:en',
    ]);
  });

  it('builds Page routes and finds the next conflicting Page', () => {
    const queue = getConflictQueue({
      'canvas_page:1:en': makeChange({ entity_id: 1, updated: 300 }),
      'canvas_page:2:en': makeChange({ entity_id: 2, updated: 200 }),
    });

    expect(findConflictIndex(queue, 'canvas_page', '2')).toBe(1);
    expect(getConflictRouteFromEntry(queue[0])).toBe('/conflict/canvas_page/1');
    expect(getNextConflictEntry(queue, 'canvas_page:1:en')?.pointer).toBe(
      'canvas_page:2:en',
    );
  });
});
