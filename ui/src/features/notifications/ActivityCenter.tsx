import { Cross2Icon } from '@radix-ui/react-icons';

import { sortNotifications } from '@/features/notifications/sortNotifications';

import NotificationCard from './NotificationCard';

import type { Notification } from '@/services/notificationsApi';

import styles from './ActivityCenter.module.css';

interface ActivityCenterProps {
  notifications: Notification[];
  onClose: () => void;
  onMarkAllRead: () => void;
  onMarkRead: (id: string) => void;
}

const ActivityCenter = ({
  notifications,
  onClose,
  onMarkAllRead,
  onMarkRead,
}: ActivityCenterProps) => {
  const sorted = sortNotifications(notifications);

  return (
    <div className={styles.panel}>
      <div className={styles.headerTop}>
        <h2 className={styles.heading}>Activity Center</h2>
        <button
          aria-label="Close"
          className={styles.closeButton}
          onClick={onClose}
          type="button"
        >
          <Cross2Icon />
        </button>
      </div>
      {notifications.length > 0 && (
        <div className={styles.headerBottom}>
          <button
            className={styles.markAllRead}
            onClick={onMarkAllRead}
            type="button"
          >
            Mark all as read
          </button>
        </div>
      )}
      {sorted.length === 0 ? (
        <div className={styles.empty}>
          <p className={styles.emptyTitle}>No notifications yet</p>
          <p className={styles.emptySubtitle}>
            New notifications will appear here
          </p>
        </div>
      ) : (
        <div className={styles.list}>
          {sorted.map((notification) => (
            <NotificationCard
              key={notification.id}
              notification={notification}
              onMarkRead={onMarkRead}
            />
          ))}
        </div>
      )}
    </div>
  );
};

export default ActivityCenter;
