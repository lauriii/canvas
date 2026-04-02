import clsx from 'clsx';
import {
  CheckCircledIcon,
  CrossCircledIcon,
  ExclamationTriangleIcon,
  InfoCircledIcon,
  UpdateIcon,
} from '@radix-ui/react-icons';

import { formatTimestamp } from '@/features/notifications/formatTimestamp';

import type { Notification } from '@/services/notificationsApi';

import styles from './NotificationCard.module.css';

const TYPE_ICONS: Record<Notification['type'], React.ComponentType> = {
  processing: UpdateIcon,
  success: CheckCircledIcon,
  info: InfoCircledIcon,
  warning: ExclamationTriangleIcon,
  error: CrossCircledIcon,
};

const SHOW_READ_INDICATOR = new Set(['info', 'warning', 'error']);

interface NotificationCardProps {
  notification: Notification;
  onMarkRead: (id: string) => void;
}

const NotificationCard = ({
  notification,
  onMarkRead,
}: NotificationCardProps) => {
  const Icon = TYPE_ICONS[notification.type];
  const showReadIndicator = SHOW_READ_INDICATOR.has(notification.type);

  return (
    <div className={styles.card} data-type={notification.type}>
      <div
        className={clsx(styles.icon, {
          [styles.spin]: notification.type === 'processing',
        })}
        data-type={notification.type}
      >
        <Icon />
      </div>
      <div className={styles.content}>
        <p className={styles.title}>{notification.title}</p>
        <p className={styles.message}>{notification.message}</p>
        {notification.actions && notification.actions.length > 0 && (
          <div className={styles.actions}>
            {notification.actions.map((action, i) => (
              <span key={action.href}>
                {i > 0 && <span className={styles.actionSeparator}> | </span>}
                <a
                  href={action.href}
                  className={styles.actionLink}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  {action.label}
                </a>
              </span>
            ))}
          </div>
        )}
      </div>
      <div className={styles.rightColumn}>
        {showReadIndicator && !notification.hasRead ? (
          <button
            className={styles.readIndicator}
            onClick={() => onMarkRead(notification.id)}
            aria-label="Mark as read"
          >
            <span className={styles.dot} />
          </button>
        ) : null}
        <span className={styles.timestamp}>
          {formatTimestamp(notification.timestamp)}
        </span>
      </div>
    </div>
  );
};

export default NotificationCard;
