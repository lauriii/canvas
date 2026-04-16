import { useCallback, useEffect, useRef } from 'react';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  removeDrupalPreviewMessage,
  selectDrupalPreviewMessages,
} from '@/features/notifications/previewMessagesSlice';

import { TOAST_DURATION } from './constants';
import NotificationToast from './NotificationToast';

import styles from './NotificationToastManager.module.css';

/**
 * Renders Drupal preview-message toasts.
 *
 * Reuses the NotificationToast visual but reads from a client-only slice so
 * preview messages never land in the activity center.
 */
const DrupalPreviewMessageToaster = () => {
  const dispatch = useAppDispatch();
  const notifications = useAppSelector(selectDrupalPreviewMessages);
  const timers = useRef(new Map<string, ReturnType<typeof setTimeout>>());

  const dismiss = useCallback(
    (id: string) => {
      const timer = timers.current.get(id);
      if (timer) {
        clearTimeout(timer);
        timers.current.delete(id);
      }
      dispatch(removeDrupalPreviewMessage(id));
    },
    [dispatch],
  );

  useEffect(() => {
    const current = timers.current;
    for (const notification of notifications) {
      if (current.has(notification.id)) {
        continue;
      }
      const timer = setTimeout(() => {
        current.delete(notification.id);
        dispatch(removeDrupalPreviewMessage(notification.id));
      }, TOAST_DURATION);
      current.set(notification.id, timer);
    }
    // Clear timers for notifications that have been removed externally.
    const ids = new Set(notifications.map((n) => n.id));
    for (const [id, timer] of current) {
      if (!ids.has(id)) {
        clearTimeout(timer);
        current.delete(id);
      }
    }
  }, [notifications, dispatch]);

  useEffect(() => {
    const current = timers.current;
    return () => {
      for (const timer of current.values()) {
        clearTimeout(timer);
      }
      current.clear();
    };
  }, []);

  if (notifications.length === 0) {
    return null;
  }

  return (
    <div
      className={styles.container}
      role="region"
      aria-label="Notifications"
      data-testid="drupal-preview-messages"
    >
      {notifications.map((notification) => (
        <NotificationToast
          key={notification.id}
          notification={notification}
          onDismiss={dismiss}
          onAction={dismiss}
        />
      ))}
    </div>
  );
};

export default DrupalPreviewMessageToaster;
