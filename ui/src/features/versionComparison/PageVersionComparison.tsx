import { useEffect, useMemo, useState } from 'react';
import { Flex, Text } from '@radix-ui/themes';

import { PageVersionComparisonView } from '@/features/versionComparison/PageVersionComparisonView';
import { useGetConflictPageLayoutQuery } from '@/services/componentAndLayout';

import type { PageVersionSelection } from '@/features/versionComparison/PageVersionComparisonView';
import type { LayoutApiResponse } from '@/services/componentAndLayout';

const EMPTY_HTML = '<main></main>';

const versionUpdatedDateFormatter = new Intl.DateTimeFormat(undefined, {
  month: 'numeric',
  day: 'numeric',
  year: '2-digit',
});

const versionUpdatedTimeFormatter = new Intl.DateTimeFormat(undefined, {
  hour: 'numeric',
  minute: '2-digit',
});

const formatPageVersionUpdated = (
  timestamp: number | undefined,
): string | undefined => {
  if (typeof timestamp !== 'number' || !Number.isFinite(timestamp)) {
    return undefined;
  }

  const date = new Date(
    timestamp < 1_000_000_000_000 ? timestamp * 1000 : timestamp,
  );

  if (Number.isNaN(date.getTime())) {
    return undefined;
  }

  const formattedDate = versionUpdatedDateFormatter.format(date);
  const formattedTime = versionUpdatedTimeFormatter.format(date);

  return Drupal.t('Updated !date at !time', {
    '!date': formattedDate,
    '!time': formattedTime,
  });
};

export interface PageVersionComparisonProps {
  entityId: string;
  entityType: string;
  autoSaveUpdated?: number;
  draftVersionKey?: string;
  selectedVersion?: PageVersionSelection;
  onSelectVersion?: (version: Exclude<PageVersionSelection, undefined>) => void;
  onPublishedPreviewClick?: () => void;
  onNewPreviewClick?: () => void;
  onNewEditClick?: () => void;
  publishedVersionTitle?: string;
  newVersionTitle?: string;
  showPreviewActions?: boolean;
}

export const PageVersionComparison = ({
  entityId,
  entityType,
  autoSaveUpdated,
  draftVersionKey,
  selectedVersion,
  onSelectVersion,
  onPublishedPreviewClick,
  onNewPreviewClick,
  onNewEditClick,
  publishedVersionTitle,
  newVersionTitle,
  showPreviewActions,
}: PageVersionComparisonProps) => {
  const draftSnapshotKey = useMemo(
    () => `${entityType}:${entityId}:draft:${draftVersionKey ?? 'current'}`,
    [draftVersionKey, entityId, entityType],
  );
  const publishedSnapshotKey = useMemo(
    () => `${entityType}:${entityId}:published`,
    [entityId, entityType],
  );
  const [draftLayoutSnapshot, setDraftLayoutSnapshot] = useState<{
    key: string;
    layout: LayoutApiResponse;
  }>();
  const [publishedLayoutSnapshot, setPublishedLayoutSnapshot] = useState<{
    key: string;
    layout: LayoutApiResponse;
  }>();
  const {
    currentData: draftLayout,
    isFetching: isDraftLoading,
    isError: isDraftError,
  } = useGetConflictPageLayoutQuery({
    entityId,
    entityType: 'canvas_page',
    versionKey: draftVersionKey,
  });
  const {
    currentData: publishedLayout,
    isFetching: isPublishedLoading,
    isError: isPublishedError,
  } = useGetConflictPageLayoutQuery({
    entityId,
    entityType: 'canvas_page',
    publishedVersion: true,
  });

  useEffect(() => {
    if (!draftLayout) {
      return;
    }
    setDraftLayoutSnapshot((currentSnapshot) =>
      currentSnapshot?.key === draftSnapshotKey
        ? currentSnapshot
        : { key: draftSnapshotKey, layout: draftLayout },
    );
  }, [draftLayout, draftSnapshotKey]);

  useEffect(() => {
    if (!publishedLayout) {
      return;
    }
    setPublishedLayoutSnapshot((currentSnapshot) =>
      currentSnapshot?.key === publishedSnapshotKey
        ? currentSnapshot
        : { key: publishedSnapshotKey, layout: publishedLayout },
    );
  }, [publishedLayout, publishedSnapshotKey]);

  const stableDraftLayout =
    draftLayoutSnapshot?.key === draftSnapshotKey
      ? draftLayoutSnapshot.layout
      : draftLayout;
  const stablePublishedLayout =
    publishedLayoutSnapshot?.key === publishedSnapshotKey
      ? publishedLayoutSnapshot.layout
      : publishedLayout;

  if (
    (!stableDraftLayout && isDraftError) ||
    (!stablePublishedLayout && isPublishedError)
  ) {
    return (
      <Flex
        align="center"
        justify="center"
        height="100%"
        data-testid="page-version-preview-error"
      >
        <Text color="red">
          {Drupal.t(
            'Unable to load both versions of this page for comparison.',
          )}
        </Text>
      </Flex>
    );
  }

  return (
    <PageVersionComparisonView
      entityId={entityId}
      entityType={entityType}
      publishedVersion={{
        html: stablePublishedLayout?.html || EMPTY_HTML,
        loading: !stablePublishedLayout && isPublishedLoading,
        updated: formatPageVersionUpdated(stablePublishedLayout?.updated),
      }}
      newVersion={{
        html: stableDraftLayout?.html || EMPTY_HTML,
        loading: !stableDraftLayout && isDraftLoading,
        changed: true,
        updated: formatPageVersionUpdated(
          stableDraftLayout?.updated ?? autoSaveUpdated,
        ),
      }}
      selectedVersion={selectedVersion}
      onSelectVersion={onSelectVersion}
      onPublishedPreviewClick={onPublishedPreviewClick}
      onNewPreviewClick={onNewPreviewClick}
      onNewEditClick={onNewEditClick}
      publishedVersionTitle={publishedVersionTitle}
      newVersionTitle={newVersionTitle}
      showPreviewActions={showPreviewActions}
    />
  );
};
