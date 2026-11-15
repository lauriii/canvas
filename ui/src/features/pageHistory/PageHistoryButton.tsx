import { useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router';
import { CounterClockwiseClockIcon, Cross2Icon } from '@radix-ui/react-icons';
import { Popover, ScrollArea, Tooltip } from '@radix-ui/themes';

import PageHistory from '@/features/pageHistory/PageHistory';
import RestoreRevisionDialog from '@/features/pageHistory/RestoreRevisionDialog';
import { useRestorePageRevision } from '@/features/pageHistory/useRestorePageRevision';
import { useGetPageRevisionsQuery } from '@/services/content';

import type { PageRevision } from '@/services/content';

import styles from '@/features/pageHistory/PageHistoryButton.module.css';

/**
 * The history of the page being edited, opened from the top bar.
 */
const PageHistoryButton = () => {
  const { entityType, entityId } = useParams();
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const [isOpen, setIsOpen] = useState(false);
  // The version waiting for confirmation. The dialog is outside the popover:
  // a click in it would otherwise close the popover, and the dialog with it.
  const [toRestore, setToRestore] = useState<PageRevision | null>(null);
  const { restoreRevision } = useRestorePageRevision();
  // Already loaded for the list; read only while confirming.
  const { data } = useGetPageRevisionsQuery(entityId ?? '', {
    skip: toRestore === null || !entityId,
  });

  // A page's history is per page: only while editing one, or comparing its
  // versions.
  if (
    entityType !== 'canvas_page' ||
    !entityId ||
    !(pathname.includes('/editor') || pathname.startsWith('/history'))
  ) {
    return null;
  }

  return (
    <>
      <Popover.Root open={isOpen} onOpenChange={setIsOpen}>
        <Tooltip content="History">
          <Popover.Trigger>
            <button
              type="button"
              className={styles.historyButton}
              aria-label="History"
            >
              <CounterClockwiseClockIcon width="16" height="16" />
            </button>
          </Popover.Trigger>
        </Tooltip>
        <Popover.Content size="1" align="end" style={{ padding: 0 }}>
          <div className={styles.panel}>
            <div className={styles.header}>
              <h2 className={styles.heading}>History</h2>
              <button
                type="button"
                aria-label="Close"
                className={styles.closeButton}
                onClick={() => setIsOpen(false)}
              >
                <Cross2Icon />
              </button>
            </div>
            <ScrollArea scrollbars="vertical" className={styles.scrollArea}>
              <PageHistory
                entityId={entityId}
                onCompare={(revision) => {
                  setIsOpen(false);
                  navigate(`/history/canvas_page/${entityId}/${revision.id}`);
                }}
                onRestore={(revision) => {
                  setIsOpen(false);
                  setToRestore(revision);
                }}
              />
            </ScrollArea>
          </div>
        </Popover.Content>
      </Popover.Root>
      <RestoreRevisionDialog
        revision={toRestore}
        draft={data?.draft ?? null}
        onCancel={() => setToRestore(null)}
        onRestore={async (revision) => {
          setToRestore(null);
          if (
            (await restoreRevision(entityId, revision.id)) &&
            pathname.startsWith('/history')
          ) {
            navigate(`/editor/canvas_page/${entityId}`);
          }
        }}
      />
    </>
  );
};

export default PageHistoryButton;
