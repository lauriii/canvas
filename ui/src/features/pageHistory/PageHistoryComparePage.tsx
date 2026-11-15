import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { Cross2Icon } from '@radix-ui/react-icons';
import {
  Badge,
  Button,
  Flex,
  IconButton,
  Spinner,
  Text,
} from '@radix-ui/themes';
import { skipToken } from '@reduxjs/toolkit/query';

import { formatRevisionDate } from '@/features/pageHistory/formatRevisionDate';
import RestoreRevisionDialog from '@/features/pageHistory/RestoreRevisionDialog';
import { useRestorePageRevision } from '@/features/pageHistory/useRestorePageRevision';
import { PageVersionComparisonView } from '@/features/versionComparison/PageVersionComparisonView';
import {
  useGetPageRevisionPreviewQuery,
  useGetPageRevisionsQuery,
} from '@/services/content';

import type { PageRevision } from '@/services/content';

import styles from '@/features/versionComparison/VersionComparisonPage.module.css';

const EMPTY_HTML = '<main></main>';

/**
 * An earlier version of a page side by side with what is live, to restore.
 */
const PageHistoryComparePage = () => {
  const { entityId = '', revisionId } = useParams();
  const navigate = useNavigate();
  const { restoreRevision, isRestoring } = useRestorePageRevision();
  const [toRestore, setToRestore] = useState<PageRevision | null>(null);
  const { data } = useGetPageRevisionsQuery(entityId || skipToken);
  const revision = data?.revisions.find(({ id }) => id === Number(revisionId));
  const live = data?.revisions.find((item) => item.live);
  const { data: earlier, isFetching: isEarlierLoading } =
    useGetPageRevisionPreviewQuery(
      revision ? { entityId, revisionId: revision.id } : skipToken,
    );
  const { data: current, isFetching: isLiveLoading } =
    useGetPageRevisionPreviewQuery(
      live ? { entityId, revisionId: live.id } : skipToken,
    );
  const backToEditor = () => navigate(`/editor/canvas_page/${entityId}`);

  return (
    <div className={styles.page} data-testid="page-history-compare-page">
      <div className={styles.header}>
        <Flex align="center" gap="1" minWidth="0">
          <button
            type="button"
            className={styles.breadcrumbLink}
            onClick={backToEditor}
          >
            Canvas
          </button>
          <Text size="1" color="gray">
            /
          </Text>
          <Text size="1" className={styles.breadcrumbLabel}>
            History
          </Text>
          {revision && (
            <>
              <Text size="1" color="gray">
                /
              </Text>
              <Text size="1" className={styles.breadcrumbLabel}>
                {revision.title}
              </Text>
              <Badge color="gray" variant="soft" radius="small">
                Page
              </Badge>
            </>
          )}
        </Flex>
        <IconButton
          variant="ghost"
          color="gray"
          highContrast
          aria-label="Close"
          onClick={backToEditor}
        >
          <Cross2Icon />
        </IconButton>
      </div>
      <div className={styles.content}>
        {data && !revision ? (
          <Text color="red">This version of the page could not be found.</Text>
        ) : (
          <PageVersionComparisonView
            entityId={entityId}
            entityType="canvas_page"
            publishedVersion={{
              html: earlier?.html || EMPTY_HTML,
              loading: !earlier && isEarlierLoading,
              updated: revision && formatRevisionDate(revision.created),
            }}
            newVersion={{
              html: current?.html || EMPTY_HTML,
              loading: !current && isLiveLoading,
              updated: live && formatRevisionDate(live.created),
            }}
            publishedVersionTitle="Earlier version"
            newVersionTitle="Live now"
            showPreviewActions={false}
          />
        )}
      </div>
      <div className={styles.footer}>
        <Flex direction="column">
          <Text size="2" color="gray">
            {revision &&
              `${revision.author ?? 'Deleted user'} · ${formatRevisionDate(revision.created)}`}
          </Text>
          {data?.draft && (
            <Text size="1" color="amber">
              The page also has unpublished changes on top of the live version,
              not shown here.
            </Text>
          )}
        </Flex>
        {revision && data?.canRestore && (
          <Button disabled={isRestoring} onClick={() => setToRestore(revision)}>
            Restore this version
            <Spinner loading={isRestoring} />
          </Button>
        )}
        <RestoreRevisionDialog
          revision={toRestore}
          draft={data?.draft ?? null}
          onCancel={() => setToRestore(null)}
          onRestore={async (chosen) => {
            setToRestore(null);
            if (await restoreRevision(entityId, chosen.id)) {
              backToEditor();
            }
          }}
        />
      </div>
    </div>
  );
};

export default PageHistoryComparePage;
