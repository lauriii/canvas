import {
  Badge,
  Box,
  Button,
  Callout,
  Flex,
  Skeleton,
  Text,
} from '@radix-ui/themes';

import { formatRevisionDate } from '@/features/pageHistory/formatRevisionDate';
import { useGetPageRevisionsQuery } from '@/services/content';

import type { PageRevision } from '@/services/content';

import styles from '@/features/pageHistory/PageHistory.module.css';

interface PageHistoryProps {
  entityId: string;
  onCompare: (revision: PageRevision) => void;
  // Asks to restore a version; the caller confirms first.
  onRestore: (revision: PageRevision) => void;
}

/**
 * A page's versions, newest first, to compare with what is live or restore.
 */
const PageHistory = ({ entityId, onCompare, onRestore }: PageHistoryProps) => {
  const { data, isLoading, isError } = useGetPageRevisionsQuery(entityId, {
    refetchOnMountOrArgChange: true,
  });

  if (isLoading) {
    return <LoadingVersions />;
  }
  if (isError || !data) {
    return (
      <Box p="4">
        <Callout.Root color="red" size="1">
          <Callout.Text>
            The history of this page could not be loaded.
          </Callout.Text>
        </Callout.Root>
      </Box>
    );
  }

  return (
    <>
      <ul className={styles.list} aria-label="Versions">
        {data.draft && (
          <li className={styles.item}>
            <Flex align="center" gap="2" wrap="wrap">
              <Text size="2" weight="medium">
                Unpublished changes
              </Text>
              <Badge color="amber" variant="soft" size="1">
                Not live
              </Badge>
            </Flex>
            <Text as="p" size="1" color="gray">
              {data.draft.author ?? 'Deleted user'} ·{' '}
              {formatRevisionDate(data.draft.updated)}
            </Text>
            <Text as="p" size="1" color="gray" className={styles.message}>
              On top of the live version. Review changes to publish them.
            </Text>
          </li>
        )}
        {data.revisions.map((revision) => (
          <li key={revision.id} className={styles.item}>
            <Flex align="center" gap="2" wrap="wrap">
              <Text size="2" weight="medium">
                {formatRevisionDate(revision.created)}
              </Text>
              {revision.live && (
                <Badge color="green" variant="soft" size="1">
                  Live
                </Badge>
              )}
              {!revision.published && (
                <Badge color="gray" variant="soft" size="1">
                  Unpublished
                </Badge>
              )}
            </Flex>
            <Text as="p" size="1" color="gray">
              {revision.author ?? 'Deleted user'} · {revision.title}
            </Text>
            {revision.message && (
              <Text as="p" size="1" className={styles.message}>
                {revision.message}
              </Text>
            )}
            {!revision.live && (
              <Flex gap="3" mt="2">
                <Button
                  size="1"
                  variant="ghost"
                  onClick={() => onCompare(revision)}
                >
                  Compare with live
                </Button>
                {data.canRestore && (
                  <Button
                    size="1"
                    variant="ghost"
                    onClick={() => onRestore(revision)}
                  >
                    Restore
                  </Button>
                )}
              </Flex>
            )}
          </li>
        ))}
      </ul>
      {data.revisions.length < 2 && (
        <Text as="p" size="1" color="gray" className={styles.note}>
          Earlier versions appear here once changes to this page are published.
        </Text>
      )}
    </>
  );
};

/**
 * Rows shaped like the versions, while they load.
 */
const LoadingVersions = () => (
  <ul className={styles.list} aria-label="Loading versions" aria-busy="true">
    {[60, 50, 55].map((width) => (
      <li key={width} className={styles.item}>
        <Flex direction="column" gap="2">
          <Skeleton height="14px" width={`${width}%`} />
          <Skeleton height="10px" width="35%" />
          <Skeleton height="10px" width="45%" />
        </Flex>
      </li>
    ))}
  </ul>
);

export default PageHistory;
