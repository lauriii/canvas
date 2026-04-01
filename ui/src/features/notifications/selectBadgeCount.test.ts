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
  it('counts all unread notifications', () => {
    const notifications = [
      makeNotification({ id: '1', hasRead: false }),
      makeNotification({ id: '2', hasRead: false }),
      makeNotification({ id: '3', hasRead: true }),
    ];
    expect(computeBadgeCount(notifications)).toBe(2);
  });

  it('includes unread info and success', () => {
    const notifications = [
      makeNotification({ id: '1', type: 'info', hasRead: false }),
      makeNotification({ id: '2', type: 'success', hasRead: false }),
    ];
    expect(computeBadgeCount(notifications)).toBe(2);
  });

  it('includes unread processing', () => {
    const notifications = [
      makeNotification({ id: '1', type: 'processing', hasRead: false }),
    ];
    expect(computeBadgeCount(notifications)).toBe(1);
  });

  it('excludes read notifications', () => {
    const notifications = [
      makeNotification({ id: '1', hasRead: true }),
      makeNotification({ id: '2', hasRead: true }),
    ];
    expect(computeBadgeCount(notifications)).toBe(0);
  });

  it('returns 0 when all read', () => {
    const notifications = [
      makeNotification({ id: '1', type: 'error', hasRead: true }),
      makeNotification({ id: '2', type: 'warning', hasRead: true }),
      makeNotification({ id: '3', type: 'info', hasRead: true }),
    ];
    expect(computeBadgeCount(notifications)).toBe(0);
  });
});
