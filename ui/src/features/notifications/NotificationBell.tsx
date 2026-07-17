import { useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import BellIcon from '@assets/icons/bell.svg?react';
import { Popover } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  isConflictNotification,
  useConflictNotification,
} from '@/features/notifications/conflictNotification';
import {
  selectActivityCenterOpen,
  setActivityCenterOpen,
} from '@/features/notifications/notificationsSlice';
import { computeBadgeCount } from '@/features/notifications/selectBadgeCount';
import { useDocumentVisibility } from '@/hooks/useDocumentVisibility';
import {
  useGetNotificationsQuery,
  useMarkNotificationsReadMutation,
} from '@/services/notificationsApi';
import { getCanvasSettings } from '@/utils/drupal-globals';

import ActivityCenter from './ActivityCenter';
import {
  AUTO_READ_TYPES,
  POLLING_BACKGROUND,
  POLLING_FAST,
  POLLING_NORMAL,
} from './constants';

import styles from './NotificationBell.module.css';

const NotificationBell = () => {
  const devMode = getCanvasSettings()?.devMode;
  const navigate = useNavigate();
  const dispatch = useAppDispatch();
  const isOpen = useAppSelector(selectActivityCenterOpen);
  const isTabFocused = useDocumentVisibility();
  const [markRead] = useMarkNotificationsReadMutation();
  const hasProcessingRef = useRef(false);
  const {
    notification: conflictNotification,
    markRead: markConflictNotificationRead,
  } = useConflictNotification(!!devMode);

  const { data } = useGetNotificationsQuery(undefined, {
    pollingInterval: getPollingInterval(isTabFocused, hasProcessingRef.current),
    skip: !devMode,
  });

  const notifications = [
    ...(conflictNotification ? [conflictNotification] : []),
    ...(data?.data.notifications ?? []),
  ];
  hasProcessingRef.current = notifications.some((n) => n.type === 'processing');
  const badgeCount = computeBadgeCount(notifications);

  const handleOpenChange = (open: boolean) => {
    dispatch(setActivityCenterOpen(open));
    if (open) {
      const autoReadIds = notifications
        .filter((n) => AUTO_READ_TYPES.has(n.type) && !n.hasRead)
        .map((n) => n.id);
      if (autoReadIds.length > 0) {
        markRead({ ids: autoReadIds });
      }
    }
  };

  const handleMarkAllRead = () => {
    if (conflictNotification && !conflictNotification.hasRead) {
      markConflictNotificationRead();
    }
    const unreadIds = notifications
      .filter((n) => !isConflictNotification(n.id) && !n.hasRead)
      .map((n) => n.id);
    if (unreadIds.length > 0) {
      markRead({ ids: unreadIds });
    }
  };

  const handleMarkRead = (id: string) => {
    if (isConflictNotification(id)) {
      markConflictNotificationRead();
      return;
    }
    markRead({ ids: [id] });
  };

  const handleAction = (id: string, href: string) => {
    if (isConflictNotification(id)) {
      markConflictNotificationRead();
      dispatch(setActivityCenterOpen(false));
      navigate(href);
      return;
    }
    window.open(href, '_blank', 'noopener,noreferrer');
  };

  if (!devMode) return null;

  return (
    <Popover.Root open={isOpen} onOpenChange={handleOpenChange}>
      <Popover.Trigger>
        <button className={styles.bellButton} aria-label="Notifications">
          <BellIcon width="16" height="16" />
          {badgeCount > 0 && (
            <span className={styles.badge}>
              {badgeCount > 9 ? '9+' : badgeCount}
            </span>
          )}
        </button>
      </Popover.Trigger>
      <Popover.Content size="1" align="end" style={{ padding: 0 }}>
        <ActivityCenter
          notifications={notifications}
          onClose={() => handleOpenChange(false)}
          onMarkAllRead={handleMarkAllRead}
          onMarkRead={handleMarkRead}
          onAction={handleAction}
        />
      </Popover.Content>
    </Popover.Root>
  );
};

function getPollingInterval(
  isTabFocused: boolean,
  hasProcessing: boolean,
): number {
  if (!isTabFocused) return POLLING_BACKGROUND;
  if (hasProcessing) return POLLING_FAST;
  return POLLING_NORMAL;
}

export default NotificationBell;
