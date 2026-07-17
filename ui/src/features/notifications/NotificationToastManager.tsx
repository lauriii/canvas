import { useCallback, useEffect, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';

import { useAppSelector } from '@/app/hooks';
import {
  isConflictNotification,
  useConflictNotification,
} from '@/features/notifications/conflictNotification';
import { selectPageOpenedAt } from '@/features/notifications/notificationsSlice';
import {
  useGetNotificationsQuery,
  useMarkNotificationsReadMutation,
} from '@/services/notificationsApi';

import { SUCCESS_TOAST_DURATION, TOAST_DURATION } from './constants';
import NotificationToast from './NotificationToast';

import type { ConflictNotification } from '@/features/notifications/conflictNotification';
import type { Notification } from '@/services/notificationsApi';

import styles from './NotificationToastManager.module.css';

const COMPONENT_SYNC_NOTIFICATION_KEY = 'headless-component-sync';

const NotificationToastManager = () => {
  const { data } = useGetNotificationsQuery();
  const [markRead] = useMarkNotificationsReadMutation();
  const navigate = useNavigate();
  const location = useLocation();
  const pageOpenedAt = useAppSelector(selectPageOpenedAt);
  const shownIds = useRef(new Set<string>());
  const [visibleToasts, setVisibleToasts] = useState<Notification[]>([]);
  const timers = useRef(new Map<string, ReturnType<typeof setTimeout>>());
  const isConflictResolverPath = location.pathname.startsWith('/conflict');
  const {
    notification: conflictNotification,
    markRead: markConflictNotificationRead,
    markShown: markConflictNotificationShown,
    shouldShowToast: shouldShowConflictToast,
  } = useConflictNotification();

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
      if (isConflictNotification(id)) {
        const notification = visibleToasts.find(
          (toast): toast is ConflictNotification =>
            toast.id === id && isConflictNotification(toast.id),
        );
        markConflictNotificationRead(notification);
        dismissToast(id);
        return;
      }
      markRead({ ids: [id] });
      dismissToast(id);
    },
    [markRead, dismissToast, markConflictNotificationRead, visibleToasts],
  );

  const handleAction = useCallback(
    (id: string, href: string) => {
      if (isConflictNotification(id)) {
        const notification = visibleToasts.find(
          (toast): toast is ConflictNotification =>
            toast.id === id && isConflictNotification(toast.id),
        );
        markConflictNotificationRead(notification);
        dismissToast(id);
        navigate(href);
        return;
      }
      markRead({ ids: [id] });
      dismissToast(id);
      window.open(href, '_blank', 'noopener,noreferrer');
    },
    [
      markRead,
      dismissToast,
      navigate,
      markConflictNotificationRead,
      visibleToasts,
    ],
  );

  // Remove conflict toasts if the user reaches the resolver by another route.
  useEffect(() => {
    if (!isConflictResolverPath) return;

    const conflictToastIds = visibleToasts
      .filter((notification) => isConflictNotification(notification.id))
      .map((notification) => notification.id);

    if (conflictToastIds.length === 0) return;

    for (const id of conflictToastIds) {
      const timer = timers.current.get(id);
      if (timer) {
        clearTimeout(timer);
        timers.current.delete(id);
      }
    }

    setVisibleToasts((prev) =>
      prev.filter((notification) => !isConflictNotification(notification.id)),
    );
  }, [isConflictResolverPath, visibleToasts]);

  // Queue backend toasts and the synthetic conflict toast when appropriate.
  useEffect(() => {
    const newToasts: Notification[] = [];

    for (const notification of data?.data.notifications ?? []) {
      if (notification.timestamp <= pageOpenedAt) continue;
      if (shownIds.current.has(notification.id)) continue;

      shownIds.current.add(notification.id);
      // Component synchronization has an in-context spinner next to the
      // frontend selector. Keep its processing state in the activity center,
      // but do not flash a redundant toast for short synchronizations.
      if (
        notification.type === 'processing' &&
        notification.key === COMPONENT_SYNC_NOTIFICATION_KEY
      ) {
        continue;
      }
      newToasts.push(notification);

      const duration =
        notification.type === 'success'
          ? SUCCESS_TOAST_DURATION
          : TOAST_DURATION;
      const timer = setTimeout(() => {
        timers.current.delete(notification.id);
        setVisibleToasts((prev) =>
          prev.filter((n) => n.id !== notification.id),
        );
      }, duration);
      timers.current.set(notification.id, timer);
    }

    if (
      conflictNotification &&
      shouldShowConflictToast &&
      !shownIds.current.has(conflictNotification.id)
    ) {
      shownIds.current.add(conflictNotification.id);
      markConflictNotificationShown();

      // If the resolver is already open, remember it as shown without raising a toast.
      if (!isConflictResolverPath) {
        newToasts.unshift(conflictNotification);

        const timer = setTimeout(() => {
          timers.current.delete(conflictNotification.id);
          setVisibleToasts((prev) =>
            prev.filter((n) => n.id !== conflictNotification.id),
          );
        }, TOAST_DURATION);
        timers.current.set(conflictNotification.id, timer);
      }
    }

    if (newToasts.length > 0) {
      // A keyed notification represents a lifecycle transition. Replace the
      // prior toast for that operation instead of showing, for example,
      // "completed" above a stale "in progress" toast.
      setVisibleToasts((prev) => {
        const replacedIds = new Set(
          newToasts.flatMap((notification) =>
            notification.key
              ? prev
                  .filter((visible) => visible.key === notification.key)
                  .map((visible) => visible.id)
              : [],
          ),
        );
        for (const id of replacedIds) {
          const timer = timers.current.get(id);
          if (timer) {
            clearTimeout(timer);
            timers.current.delete(id);
          }
        }
        return [
          ...newToasts,
          ...prev.filter((notification) => !replacedIds.has(notification.id)),
        ];
      });
    }
  }, [
    data?.data.notifications,
    pageOpenedAt,
    conflictNotification,
    shouldShowConflictToast,
    markConflictNotificationShown,
    isConflictResolverPath,
  ]);

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
