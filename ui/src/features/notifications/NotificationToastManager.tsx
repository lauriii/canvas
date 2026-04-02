import { useCallback, useEffect, useRef, useState } from 'react';

import { useAppSelector } from '@/app/hooks';
import { selectPageOpenedAt } from '@/features/notifications/notificationsSlice';
import {
  useGetNotificationsQuery,
  useMarkNotificationsReadMutation,
} from '@/services/notificationsApi';

import { TOAST_DURATION } from './constants';
import NotificationToast from './NotificationToast';

import type { Notification } from '@/services/notificationsApi';

import styles from './NotificationToastManager.module.css';

const NotificationToastManager = () => {
  const { data } = useGetNotificationsQuery();
  const [markRead] = useMarkNotificationsReadMutation();
  const pageOpenedAt = useAppSelector(selectPageOpenedAt);
  const shownIds = useRef(new Set<string>());
  const [visibleToasts, setVisibleToasts] = useState<Notification[]>([]);
  const timers = useRef(new Map<string, ReturnType<typeof setTimeout>>());

  const dismissToast = useCallback((id: string) => {
    const timer = timers.current.get(id);
    if (timer) {
      clearTimeout(timer);
      timers.current.delete(id);
    }
    setVisibleToasts((prev) => prev.filter((n) => n.id !== id));
  }, []);

  const handleDismiss = useCallback(
    (id: string) => {
      markRead({ ids: [id] });
      dismissToast(id);
    },
    [markRead, dismissToast],
  );

  const handleAction = useCallback(
    (id: string, href: string) => {
      markRead({ ids: [id] });
      dismissToast(id);
      window.open(href, '_blank', 'noopener,noreferrer');
    },
    [markRead, dismissToast],
  );

  useEffect(() => {
    if (!data?.data.notifications) return;

    const newToasts: Notification[] = [];

    for (const notification of data.data.notifications) {
      if (notification.timestamp <= pageOpenedAt) continue;
      if (shownIds.current.has(notification.id)) continue;

      shownIds.current.add(notification.id);
      newToasts.push(notification);

      const timer = setTimeout(() => {
        timers.current.delete(notification.id);
        setVisibleToasts((prev) =>
          prev.filter((n) => n.id !== notification.id),
        );
      }, TOAST_DURATION);
      timers.current.set(notification.id, timer);
    }

    if (newToasts.length > 0) {
      setVisibleToasts((prev) => [...newToasts, ...prev]);
    }
  }, [data?.data.notifications, pageOpenedAt]);

  useEffect(() => {
    const currentTimers = timers.current;
    return () => {
      for (const timer of currentTimers.values()) {
        clearTimeout(timer);
      }
    };
  }, []);

  if (visibleToasts.length === 0) return null;

  return (
    <div className={styles.container}>
      {visibleToasts.map((notification) => (
        <NotificationToast
          key={notification.id}
          notification={notification}
          onDismiss={handleDismiss}
          onAction={handleAction}
        />
      ))}
    </div>
  );
};

export default NotificationToastManager;
