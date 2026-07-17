import { beforeEach, describe, expect, it } from 'vitest';

import {
  CONFLICT_NOTIFICATION_KEY,
  createConflictNotification,
  markConflictNotificationRead,
  markConflictNotificationShown,
  shouldShowConflictNotificationToast,
} from './conflictNotification';

import type {
  PendingChange,
  PendingChanges,
} from '@/services/pendingChangesApi';

const pendingChange: PendingChange = {
  owner: {
    name: 'Editor',
    avatar: null,
    uri: '/user/2',
    id: 2,
  },
  entity_type: 'canvas_page',
  entity_id: '2',
  data_hash: 'hash-2',
  langcode: 'en',
  label: 'Page 2',
  updated: 1_777_000_000,
  hasConflict: true,
  conflict_id: '17',
};

const makeChanges = (
  overrides: Partial<PendingChange> = {},
): PendingChanges => ({
  'canvas_page:2:en': {
    ...pendingChange,
    ...overrides,
  },
});

const makeSecondChange = (
  overrides: Partial<PendingChange> = {},
): PendingChange => ({
  ...pendingChange,
  entity_id: '3',
  data_hash: 'hash-3',
  conflict_id: '18',
  label: 'Page 3',
  updated: 1_777_000_100,
  ...overrides,
});

describe('conflictNotification', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('creates a warning notification from conflicted pending changes', () => {
    const notification = createConflictNotification(makeChanges());

    expect(notification).toMatchObject({
      type: 'warning',
      key: CONFLICT_NOTIFICATION_KEY,
      title: 'Conflict detected',
      message: 'One or more Canvas auto-save items have conflicts.',
      timestamp: 1_777_000_000_000,
      hasRead: false,
      actions: [
        {
          label: 'Resolve conflicts',
          href: '/conflict/canvas_page/2',
        },
      ],
    });
  });

  it('links to the newest conflicted pending change first', () => {
    const notification = createConflictNotification({
      'canvas_page:2:en': {
        ...pendingChange,
        updated: 1_777_000_000,
      },
      'canvas_page:3:en': {
        ...pendingChange,
        entity_id: '3',
        data_hash: 'hash-3',
        conflict_id: '18',
        label: 'Page 3',
        updated: 1_777_000_100,
      },
    });

    expect(notification?.actions?.[0]?.href).toBe('/conflict/canvas_page/3');
  });

  it('does not create a notification when there are no conflicted changes', () => {
    expect(
      createConflictNotification(
        makeChanges({ hasConflict: false, conflict_id: undefined }),
      ),
    ).toBeUndefined();
  });

  it('stores shown conflict items to avoid repeated toast display', () => {
    const notification = createConflictNotification(makeChanges());
    expect(notification).toBeDefined();

    expect(shouldShowConflictNotificationToast(notification!)).toBe(true);
    markConflictNotificationShown(notification!);
    expect(shouldShowConflictNotificationToast(notification!)).toBe(false);
  });

  it('does not show another toast when one conflict is removed from a shown set', () => {
    const first = createConflictNotification({
      'canvas_page:2:en': pendingChange,
      'canvas_page:3:en': makeSecondChange(),
    });
    expect(first).toBeDefined();

    markConflictNotificationShown(first!);

    const remaining = createConflictNotification({
      'canvas_page:3:en': makeSecondChange(),
    });
    expect(remaining).toBeDefined();
    expect(shouldShowConflictNotificationToast(remaining!)).toBe(false);
  });

  it('shows another toast when a new conflict is added to a shown set', () => {
    const first = createConflictNotification(makeChanges());
    expect(first).toBeDefined();

    markConflictNotificationShown(first!);

    const second = createConflictNotification({
      'canvas_page:2:en': pendingChange,
      'canvas_page:3:en': makeSecondChange(),
    });
    expect(second).toBeDefined();
    expect(shouldShowConflictNotificationToast(second!)).toBe(true);
  });

  it('treats changed conflict content as a new notification', () => {
    const first = createConflictNotification(makeChanges());
    const second = createConflictNotification(
      makeChanges({ data_hash: 'new' }),
    );
    expect(first).toBeDefined();
    expect(second).toBeDefined();

    markConflictNotificationShown(first!);

    expect(shouldShowConflictNotificationToast(first!)).toBe(false);
    expect(shouldShowConflictNotificationToast(second!)).toBe(true);
  });

  it('marks the current conflict notification as read in localStorage', () => {
    const notification = createConflictNotification(makeChanges());
    expect(notification?.hasRead).toBe(false);

    markConflictNotificationRead(notification!);

    expect(createConflictNotification(makeChanges())?.hasRead).toBe(true);
  });

  it('keeps remaining conflicts read when one read conflict is removed', () => {
    const first = createConflictNotification({
      'canvas_page:2:en': pendingChange,
      'canvas_page:3:en': makeSecondChange(),
    });
    expect(first).toBeDefined();

    markConflictNotificationRead(first!);

    const remaining = createConflictNotification({
      'canvas_page:3:en': makeSecondChange(),
    });
    expect(remaining?.hasRead).toBe(true);
  });
});
