import { describe, expect, it } from 'vitest';

import {
  findConcurrentEditor,
  groupPresenceByUser,
} from '@/features/presence/presenceSlice';
import { getPresenceTarget } from '@/features/presence/usePresence';

import type { PresenceSession } from '@/features/presence/presenceSlice';

const session = (overrides: Partial<PresenceSession>): PresenceSession => ({
  clientInstanceId: 'tab',
  uid: 2,
  displayName: 'Alex',
  avatar: null,
  self: false,
  selection: [],
  active: true,
  ...overrides,
});

describe('groupPresenceByUser', () => {
  it('merges tabs per user and orders active people first, own tabs last', () => {
    const people = groupPresenceByUser([
      session({ clientInstanceId: 'me-2', uid: 1, self: true }),
      session({
        clientInstanceId: 'blake',
        uid: 3,
        displayName: 'Blake',
        active: false,
      }),
      session({ clientInstanceId: 'alex-1', active: false, selection: ['a'] }),
      session({ clientInstanceId: 'alex-2', selection: ['a', 'b'] }),
    ]);
    expect(people.map((p) => [p.uid, p.active, p.selection])).toEqual([
      [2, true, ['a', 'b']],
      [3, false, []],
      [1, true, []],
    ]);
  });
});

describe('findConcurrentEditor', () => {
  const alex = session({ clientInstanceId: 'alex-1' });

  it('finds the present session that wrote the pending changes', () => {
    expect(
      findConcurrentEditor({
        sessions: [alex],
        pendingChangesBy: { ...alex, clientInstanceId: 'alex-1', updated: 1 },
      }),
    ).toBe(alex);
  });

  it('ignores authors who left, and items without pending changes', () => {
    expect(
      findConcurrentEditor({
        sessions: [alex],
        pendingChangesBy: { ...alex, clientInstanceId: 'gone', updated: 1 },
      }),
    ).toBeUndefined();
    expect(
      findConcurrentEditor({ sessions: [alex], pendingChangesBy: null }),
    ).toBeUndefined();
  });
});

describe('getPresenceTarget', () => {
  it.each([
    ['/editor/canvas_page/1', { entityType: 'canvas_page', entityId: '1' }],
    [
      '/editor/page_variant/default/component/abc',
      { entityType: 'page_variant', entityId: 'default' },
    ],
    [
      '/template/node/article/full/5',
      { entityType: 'content_template', entityId: 'node.article.full' },
    ],
    ['/pattern/hero', { entityType: 'pattern', entityId: 'hero' }],
    [
      '/code-editor/code/card',
      { entityType: 'js_component', entityId: 'card' },
    ],
    [
      '/code-editor/component/card',
      { entityType: 'js_component', entityId: 'card' },
    ],
    ['/preview/canvas_page/1', null],
    ['/editor', null],
  ])('%s', (path, expected) => {
    expect(getPresenceTarget(path)).toEqual(expected);
  });
});
