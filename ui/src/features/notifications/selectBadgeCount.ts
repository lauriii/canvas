import type { Notification } from '@/services/notificationsApi';

export function computeBadgeCount(notifications: Notification[]): number {
  return notifications.filter((n) => !n.hasRead).length;
}
