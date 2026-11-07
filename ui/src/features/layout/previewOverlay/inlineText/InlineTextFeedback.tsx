import { useEffect, useState } from 'react';
import clsx from 'clsx';
import ReactDOM from 'react-dom';
import { Cross2Icon } from '@radix-ui/react-icons';

import styles from './InlineTextToolbar.module.css';

export interface InlineTextNotice {
  id: string;
  message: string;
  tone: 'info' | 'error';
  actions?: Array<{ label: string; onClick: () => void }>;
  /** Milliseconds until it disappears; errors stay until dismissed. */
  timeout?: number;
}

interface InlineTextFeedbackProps {
  notices: InlineTextNotice[];
  onDismiss: (id: string) => void;
}

/**
 * Notices about inline text editing that need the author's attention.
 *
 * Notices are also announced to screen readers.
 */
const InlineTextFeedback: React.FC<InlineTextFeedbackProps> = ({
  notices,
  onDismiss,
}) => {
  // Timed notices wait while the author points at or focuses them, so there
  // is always time to use their actions.
  const [paused, setPaused] = useState(false);
  useEffect(() => {
    if (paused) {
      return;
    }
    const timers = notices
      .filter((notice) => notice.timeout)
      .map((notice) => setTimeout(() => onDismiss(notice.id), notice.timeout));
    return () => timers.forEach(clearTimeout);
  }, [notices, onDismiss, paused]);
  useEffect(() => {
    if (!notices.length) {
      setPaused(false);
    }
  }, [notices.length]);

  // Docked at the top of the preview, below its controls, where notices
  // cover the least of the page.
  const pane = (() => {
    let element: HTMLElement | null =
      document.querySelector<HTMLElement>(
        'iframe[data-canvas-preview][data-canvas-swap-active="true"]',
      )?.parentElement ?? null;
    while (element && getComputedStyle(element).overflow === 'visible') {
      element = element.parentElement;
    }
    return element?.getBoundingClientRect();
  })();

  const portalRoot = document.getElementById('canvasPreviewOverlay');
  if (!portalRoot) {
    return null;
  }
  return ReactDOM.createPortal(
    <>
      <div
        className={styles.notices}
        role="status"
        aria-live="polite"
        style={
          pane
            ? // In the band above the page, with the preview's controls.
              { top: pane.top + 10, left: pane.left + pane.width / 2 }
            : undefined
        }
        onMouseEnter={() => setPaused(true)}
        onMouseLeave={(event) =>
          setPaused(event.currentTarget.contains(document.activeElement))
        }
        onFocus={() => setPaused(true)}
        onBlur={(event) =>
          setPaused(
            event.currentTarget.contains(event.relatedTarget as Node | null),
          )
        }
      >
        {notices.map((notice) => (
          <div
            key={notice.id}
            className={clsx(styles.notice, {
              [styles.noticeError]: notice.tone === 'error',
            })}
            data-canvas-inline-notice={notice.tone}
          >
            <span>{notice.message}</span>
            {notice.actions?.map((action) => (
              <button
                key={action.label}
                type="button"
                className={styles.noticeAction}
                onClick={() => {
                  action.onClick();
                  onDismiss(notice.id);
                }}
              >
                {action.label}
              </button>
            ))}
            <button
              type="button"
              className={styles.noticeDismiss}
              aria-label="Dismiss"
              onClick={() => onDismiss(notice.id)}
            >
              <Cross2Icon aria-hidden="true" />
            </button>
          </div>
        ))}
      </div>
    </>,
    portalRoot,
  );
};

export default InlineTextFeedback;
