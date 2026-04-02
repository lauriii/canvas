import { describe, expect, it } from 'vitest';

import { computeBadgeCount } from './selectBadgeCount';

import type { Notification } from '@/services/notificationsApi';

const makeNotification = (
  overrides: Partial<Notification> & { id: string },
): Notification => ({
  type: 'info',
  key: null,
  title: 'Test',
  message: 'Test message',
  timestamp: 1000,
  hasRead: false,
  actions: null,
  ...overrides,
});

describe('computeBadgeCount', () => {
  it('counts unread actionable notifications (info, warning, error)', () => {
    const notifications = [
      makeNotification({ id: '1', type: 'info', hasRead: false }),
      makeNotification({ id: '2', type: 'warning', hasRead: false }),
      makeNotification({ id: '3', type: 'error', hasRead: false }),
    ];
    expect(computeBadgeCount(notifications)).toBe(3);
  });

  it('excludes success and processing from badge count', () => {
    const notifications = [
      makeNotification({ id: '1', type: 'success', hasRead: false }),
      makeNotification({ id: '2', type: 'processing', hasRead: false }),
    ];
    expect(computeBadgeCount(notifications)).toBe(0);
  });

  it('excludes read notifications', () => {
    const notifications = [
      makeNotification({ id: '1', type: 'info', hasRead: true }),
      makeNotification({ id: '2', type: 'error', hasRead: true }),
    ];
    expect(computeBadgeCount(notifications)).toBe(0);
  });

  it('counts only unread actionable in a mixed set', () => {
    const notifications = [
      makeNotification({ id: '1', type: 'info', hasRead: false }),
      makeNotification({ id: '2', type: 'success', hasRead: false }),
      makeNotification({ id: '3', type: 'processing', hasRead: false }),
      makeNotification({ id: '4', type: 'warning', hasRead: true }),
      makeNotification({ id: '5', type: 'error', hasRead: false }),
    ];
    expect(computeBadgeCount(notifications)).toBe(2);
  });

  it('returns 0 for empty list', () => {
    expect(computeBadgeCount([])).toBe(0);
  });
});
