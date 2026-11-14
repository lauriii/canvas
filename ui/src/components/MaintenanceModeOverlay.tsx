import { useEffect, useRef } from 'react';
import { AlertDialog, Box, Flex } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import { selectMaintenanceMessage } from '@/features/error-handling/queryErrorSlice';
import { getDrupalSettings } from '@/utils/drupal-globals';

import styles from '@/components/MaintenanceModeOverlay.module.css';

const POLL_INTERVAL = 10000;

/**
 * Blocks the UI while the site is in maintenance mode.
 *
 * Drupal logs out users without the "access site in maintenance mode"
 * permission, so the session cannot resume. Once the site is back online, the
 * user is sent to the login page, which returns them to the current page.
 */
const MaintenanceModeOverlay = () => {
  const message = useAppSelector(selectMaintenanceMessage);
  const contentRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!message) {
      return;
    }
    const interval = setInterval(async () => {
      try {
        const response = await fetch(window.location.href, {
          method: 'HEAD',
          cache: 'no-store',
        });
        if (response.status !== 503) {
          const { pathname, search } = window.location;
          window.location.href = `${getDrupalSettings().canvas.loginUrl}?destination=${encodeURIComponent(pathname + search)}`;
        }
      } catch {
        // The site is unreachable, try again on the next interval.
      }
    }, POLL_INTERVAL);
    return () => clearInterval(interval);
  }, [message]);

  if (!message) {
    return null;
  }

  return (
    <AlertDialog.Root open>
      <AlertDialog.Content
        ref={contentRef}
        className={styles.content}
        // The dialog has no buttons to focus, so focus the dialog itself. This
        // keeps keyboard focus inside the dialog, instead of on the editor
        // behind it.
        onOpenAutoFocus={(event) => {
          event.preventDefault();
          contentRef.current?.focus({ preventScroll: true });
        }}
        // The editor's keyboard shortcuts (such as delete and undo) listen on
        // the document. Keep key presses from reaching them, so they can't
        // change the editor behind the dialog.
        onKeyDown={(event) => event.stopPropagation()}
      >
        <Flex direction="column" align="center" gap="2">
          <Box mb="3" className={styles.spinner} />
          <AlertDialog.Title size="5" mb="0">
            Site is currently under maintenance
          </AlertDialog.Title>
          <AlertDialog.Description size="3" color="gray">
            This page will reload when the site is back online.
          </AlertDialog.Description>
        </Flex>
      </AlertDialog.Content>
    </AlertDialog.Root>
  );
};

export default MaintenanceModeOverlay;
