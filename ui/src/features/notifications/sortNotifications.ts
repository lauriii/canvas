import type { Notification } from '@/services/notificationsApi';

export function sortNotifications(
  notifications: Notification[],
): Notification[] {
  return [...notifications].sort((a, b) => {
    const aPriority = getEffectivePriority(a);
    const bPriority = getEffectivePriority(b);
    if (aPriority !== bPriority) return aPriority - bPriority;
    return b.timestamp - a.timestamp;
  });
}

function getEffectivePriority(n: Notification): number {
  if (n.type === 'processing') return 0;
  if (!n.hasRead && n.type === 'error') return 1;
  if (!n.hasRead && n.type === 'warning') return 2;
  return 3;
}
