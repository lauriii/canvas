import { BADGE_TYPES } from '@/features/notifications/constants';

import type { Notification } from '@/services/notificationsApi';

export function computeBadgeCount(notifications: Notification[]): number {
  return notifications.filter((n) => !n.hasRead && BADGE_TYPES.has(n.type))
    .length;
}
