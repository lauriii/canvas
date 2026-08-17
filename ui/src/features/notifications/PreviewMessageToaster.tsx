import { useCallback, useEffect } from 'react';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  removePreviewMessage,
  selectPreviewMessages,
} from '@/features/notifications/previewMessagesSlice';

import { TOAST_DURATION } from './constants';
import NotificationToast from './NotificationToast';

import styles from './NotificationToastManager.module.css';

/**
 * Shows the status messages returned by layout preview responses as toasts.
 *
 * @see \Drupal\canvas\Render\MainContent\CanvasPreviewRenderer::collectMessages()
 */
const PreviewMessageToaster = () => {
  const dispatch = useAppDispatch();
  const notifications = useAppSelector(selectPreviewMessages);

  const dismiss = useCallback(
    (id: string) => {
      dispatch(removePreviewMessage(id));
    },
    [dispatch],
  );

  useEffect(() => {
    // Time out from when the message arrived, so that a newly added message
    // does not extend the lifetime of the ones already shown.
    const timers = notifications.map((notification) =>
      setTimeout(
        () => dismiss(notification.id),
        Math.max(0, notification.timestamp + TOAST_DURATION - Date.now()),
      ),
    );
    return () => timers.forEach(clearTimeout);
  }, [notifications, dismiss]);

  // The preview no longer renders the status messages element, which is what
  // announced these to a screen reader. Keep the region mounted even when it is
  // empty, so that a message added to it later is announced.
  return (
    <div className={styles.container} aria-live="polite">
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

export default PreviewMessageToaster;
