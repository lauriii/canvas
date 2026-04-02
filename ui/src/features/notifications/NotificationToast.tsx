import clsx from 'clsx';
import {
  CheckCircledIcon,
  Cross2Icon,
  CrossCircledIcon,
  ExclamationTriangleIcon,
  InfoCircledIcon,
  UpdateIcon,
} from '@radix-ui/react-icons';

import type { Notification } from '@/services/notificationsApi';

import styles from './NotificationToast.module.css';

const TYPE_ICONS: Record<Notification['type'], React.ComponentType> = {
  processing: UpdateIcon,
  success: CheckCircledIcon,
  info: InfoCircledIcon,
  warning: ExclamationTriangleIcon,
  error: CrossCircledIcon,
};

interface NotificationToastProps {
  notification: Notification;
  onDismiss: (id: string) => void;
  onAction: (id: string, href: string) => void;
}

const NotificationToast = ({
  notification,
  onDismiss,
  onAction,
}: NotificationToastProps) => {
  const Icon = TYPE_ICONS[notification.type];

  return (
    <div className={styles.toast} data-type={notification.type}>
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
            {notification.actions.map((action) => (
              <button
                key={action.href}
                className={styles.actionLink}
                onClick={() => onAction(notification.id, action.href)}
                type="button"
              >
                {action.label}
              </button>
            ))}
          </div>
        )}
      </div>
      <button
        className={styles.dismissButton}
        onClick={() => onDismiss(notification.id)}
        aria-label="Dismiss notification"
        type="button"
      >
        <Cross2Icon />
      </button>
    </div>
  );
};

export default NotificationToast;
