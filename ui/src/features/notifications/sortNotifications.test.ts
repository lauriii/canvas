import { describe, expect, it } from 'vitest';

import { sortNotifications } from './sortNotifications';

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

describe('sortNotifications', () => {
  it('sorts processing notifications first', () => {
    const notifications = [
      makeNotification({ id: '1', type: 'info', timestamp: 2000 }),
      makeNotification({ id: '2', type: 'processing', timestamp: 1000 }),
    ];
    const sorted = sortNotifications(notifications);
    expect(sorted[0].id).toBe('2');
    expect(sorted[1].id).toBe('1');
  });

  it('sorts unread errors before unread warnings', () => {
    const notifications = [
      makeNotification({ id: '1', type: 'warning', timestamp: 2000 }),
      makeNotification({ id: '2', type: 'error', timestamp: 1000 }),
    ];
    const sorted = sortNotifications(notifications);
    expect(sorted[0].id).toBe('2');
    expect(sorted[1].id).toBe('1');
  });

  it('sorts unread errors newest first', () => {
    const notifications = [
      makeNotification({ id: '1', type: 'error', timestamp: 1000 }),
      makeNotification({ id: '2', type: 'error', timestamp: 2000 }),
    ];
    const sorted = sortNotifications(notifications);
    expect(sorted[0].id).toBe('2');
    expect(sorted[1].id).toBe('1');
  });

  it('drops read errors to the chronological group', () => {
    const notifications = [
      makeNotification({
        id: '1',
        type: 'error',
        hasRead: true,
        timestamp: 2000,
      }),
      makeNotification({
        id: '2',
        type: 'error',
        hasRead: false,
        timestamp: 1000,
      }),
    ];
    const sorted = sortNotifications(notifications);
    expect(sorted[0].id).toBe('2');
    expect(sorted[1].id).toBe('1');
  });

  it('sorts mixed types correctly', () => {
    const notifications = [
      makeNotification({ id: '1', type: 'info', timestamp: 5000 }),
      makeNotification({ id: '2', type: 'warning', timestamp: 4000 }),
      makeNotification({ id: '3', type: 'error', timestamp: 3000 }),
      makeNotification({ id: '4', type: 'processing', timestamp: 2000 }),
      makeNotification({
        id: '5',
        type: 'error',
        hasRead: true,
        timestamp: 6000,
      }),
    ];
    const sorted = sortNotifications(notifications);
    expect(sorted.map((n) => n.id)).toEqual(['4', '3', '2', '5', '1']);
  });
});
