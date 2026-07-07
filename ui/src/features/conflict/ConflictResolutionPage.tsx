import { useEffect, useMemo, useState } from 'react';
import { Navigate, useNavigate, useParams } from 'react-router-dom';
import { toast } from 'sonner';
import {
  CheckCircledIcon,
  Cross2Icon,
  CrossCircledIcon,
} from '@radix-ui/react-icons';
import { Button, Flex, IconButton, Spinner, Text } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import {
  findConflictIndex,
  getConflictQueue,
  getConflictRouteFromEntry,
  getNextConflictEntry,
} from '@/features/conflict/conflictComparison';
import { isConflictUxEnabled } from '@/features/conflict/conflictUtils';
import { unsetActivePanel } from '@/features/ui/primaryPanelSlice';
import {
  componentAndLayoutApi,
  useGetConflictPageLayoutQuery,
} from '@/services/componentAndLayout';
import { contentApi, useUpdateContentMutation } from '@/services/content';
import {
  useDiscardPendingChangeMutation,
  useGetAllPendingChangesQuery,
} from '@/services/pendingChangesApi';

import {
  ConflictResolutionView,
  PageConflictComparisonView,
} from './ConflictResolutionView';

import type { ConflictChangeEntry } from '@/features/conflict/conflictComparison';
import type { LayoutApiResponse } from '@/services/componentAndLayout';
import type { ConflictVersionSelection } from './ConflictResolutionView';

import styles from './ConflictResolutionPage.module.css';

const EMPTY_HTML = '<main></main>';
const REVIEW_CHANGES_QUERY = 'reviewChanges=1';
const RESOLUTION_ERROR_MESSAGE =
  'Unable to resolve this conflict. Please try again.';
const showResolutionError = () => {
  const toastId = toast.error(RESOLUTION_ERROR_MESSAGE, {
    icon: <CrossCircledIcon style={{ color: 'var(--red-9)' }} />,
    action: {
      label: <Cross2Icon aria-label="Dismiss notification" />,
      onClick: () => toast.dismiss(toastId),
    },
    style: {
      justifyContent: 'flex-start',
      width: 'auto',
      minWidth: '320px',
      maxWidth: '400px',
      minHeight: '48px',
      padding: 'var(--space-3) var(--space-4)',
      whiteSpace: 'normal',
      color: 'var(--gray-12)',
      borderLeft: '4px solid var(--red-9)',
      background: 'var(--red-3)',
    },
    actionButtonStyle: {
      alignItems: 'center',
      justifyContent: 'center',
      width: '20px',
      height: '20px',
      marginLeft: 'auto',
      marginRight: 0,
      marginBottom: 'auto',
      padding: 0,
      color: 'var(--gray-11)',
      background: 'transparent',
    },
  });
};

const versionUpdatedDateFormatter = new Intl.DateTimeFormat(undefined, {
  month: 'numeric',
  day: 'numeric',
  year: '2-digit',
});

const versionUpdatedTimeFormatter = new Intl.DateTimeFormat(undefined, {
  hour: 'numeric',
  minute: '2-digit',
});

const formatConflictVersionUpdated = (
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

  return `Updated ${formattedDate} at ${formattedTime}`;
};

const ConflictResolutionPage = () =>
  isConflictUxEnabled() ? (
    <EnabledConflictResolutionPage />
  ) : (
    <Navigate to="/editor" replace />
  );

const EnabledConflictResolutionPage = () => {
  const { entityType, entityId } = useParams<{
    entityType?: string;
    entityId?: string;
  }>();
  const navigate = useNavigate();
  const dispatch = useAppDispatch();
  const {
    data: pendingChanges,
    refetch,
    isFetching: isPendingChangesFetching = false,
  } = useGetAllPendingChangesQuery(undefined);
  const [resolveConflict, { isLoading: isResolving }] =
    useUpdateContentMutation();
  const [discardChange, { isLoading: isDiscarding }] =
    useDiscardPendingChangeMutation();
  const [selectedVersion, setSelectedVersion] =
    useState<ConflictVersionSelection>();

  const conflictQueue = useMemo(
    () => getConflictQueue(pendingChanges),
    [pendingChanges],
  );
  const [conflictQueueSnapshot, setConflictQueueSnapshot] =
    useState<ConflictChangeEntry[]>();
  const [reviewPointerSnapshot, setReviewPointerSnapshot] =
    useState<string[]>();

  useEffect(() => {
    dispatch(unsetActivePanel());
  }, [dispatch]);

  useEffect(() => {
    if (pendingChanges === undefined || conflictQueueSnapshot !== undefined) {
      return;
    }
    setConflictQueueSnapshot(conflictQueue);
    setReviewPointerSnapshot(conflictQueue.map(({ pointer }) => pointer));
  }, [conflictQueue, conflictQueueSnapshot, pendingChanges]);

  const activeConflictQueue = conflictQueueSnapshot ?? conflictQueue;
  const reviewPointers =
    reviewPointerSnapshot ?? activeConflictQueue.map(({ pointer }) => pointer);
  const currentIndex = findConflictIndex(
    activeConflictQueue,
    entityType,
    entityId,
  );
  const currentEntry =
    currentIndex >= 0 ? activeConflictQueue[currentIndex] : undefined;
  const reviewPointerIndex = currentEntry
    ? reviewPointers.indexOf(currentEntry.pointer)
    : -1;
  const reviewIndex =
    reviewPointerIndex >= 0 ? reviewPointerIndex : currentIndex;
  const reviewTotal = Math.max(
    reviewPointers.length,
    activeConflictQueue.length,
  );
  const isResolutionInProgress = isResolving || isDiscarding;
  const pendingChangesCount = Object.keys(pendingChanges ?? {}).length;

  useEffect(() => {
    setSelectedVersion(undefined);
  }, [currentEntry?.pointer]);

  if (pendingChanges === undefined && isPendingChangesFetching) {
    return <ConflictResolutionLoadingState />;
  }

  if (!entityType || !entityId || !currentEntry) {
    return activeConflictQueue.length > 0 ? (
      <Navigate
        to={getConflictRouteFromEntry(activeConflictQueue[0])}
        replace
      />
    ) : (
      <ResolvedConflictState
        pendingChangesCount={pendingChangesCount}
        onReviewChanges={() => navigate(`/editor?${REVIEW_CHANGES_QUERY}`)}
        onClose={() => navigate('/editor')}
      />
    );
  }

  const activeChange = currentEntry.change;

  const finishResolution = async () => {
    const nextEntry = getNextConflictEntry(
      activeConflictQueue,
      currentEntry.pointer,
    );
    dispatch(componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]));
    dispatch(contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]));
    await refetch();
    setConflictQueueSnapshot((queue) =>
      queue?.filter(({ pointer }) => pointer !== currentEntry.pointer),
    );
    navigate(nextEntry ? getConflictRouteFromEntry(nextEntry) : '/conflict');
  };

  const handleSelectVersion = (
    version: Exclude<ConflictVersionSelection, undefined>,
  ) => {
    setSelectedVersion((currentVersion) =>
      currentVersion === version ? undefined : version,
    );
  };

  const handleResolveConflict = async () => {
    if (!selectedVersion) {
      showResolutionError();
      return;
    }

    try {
      if (selectedVersion === 'new') {
        const conflictId = activeChange.conflict_id;
        if (!conflictId) {
          showResolutionError();
          return;
        }
        await resolveConflict({
          entityType: 'canvas_page',
          entityId: String(activeChange.entity_id),
          conflictToResolve: conflictId,
        }).unwrap();
      } else {
        await discardChange({
          ...activeChange,
          pointer: currentEntry.pointer,
        }).unwrap();
      }
      await finishResolution();
    } catch {
      showResolutionError();
    }
  };

  const handleClose = () => {
    navigate(`/editor/${activeChange.entity_type}/${activeChange.entity_id}`);
  };

  return (
    <ConflictResolutionView
      label={activeChange.label}
      comparison={
        <PageConflictComparison
          key={currentEntry.pointer}
          entityId={String(activeChange.entity_id)}
          entityType={activeChange.entity_type}
          autoSaveUpdated={activeChange.updated}
          selectedVersion={selectedVersion}
          onSelectVersion={handleSelectVersion}
        />
      }
      reviewIndex={reviewIndex}
      reviewTotal={reviewTotal}
      currentIndex={currentIndex}
      unresolvedTotal={activeConflictQueue.length}
      onPrevious={() =>
        navigate(
          getConflictRouteFromEntry(activeConflictQueue[currentIndex - 1]),
        )
      }
      onNext={() =>
        navigate(
          getConflictRouteFromEntry(activeConflictQueue[currentIndex + 1]),
        )
      }
      onResolveConflict={handleResolveConflict}
      onClose={handleClose}
      isResolving={isResolutionInProgress}
      canResolveConflict={!!selectedVersion}
    />
  );
};

const PageConflictComparison = ({
  entityId,
  entityType,
  autoSaveUpdated,
  selectedVersion,
  onSelectVersion,
}: {
  entityId: string;
  entityType: string;
  autoSaveUpdated?: number;
  selectedVersion: ConflictVersionSelection;
  onSelectVersion: (
    version: Exclude<ConflictVersionSelection, undefined>,
  ) => void;
}) => {
  const [draftLayoutSnapshot, setDraftLayoutSnapshot] =
    useState<LayoutApiResponse>();
  const [publishedLayoutSnapshot, setPublishedLayoutSnapshot] =
    useState<LayoutApiResponse>();
  const {
    data: draftLayout,
    isFetching: isDraftLoading,
    isError: isDraftError,
  } = useGetConflictPageLayoutQuery({
    entityId,
    entityType: 'canvas_page',
  });
  const {
    data: publishedLayout,
    isFetching: isPublishedLoading,
    isError: isPublishedError,
  } = useGetConflictPageLayoutQuery({
    entityId,
    entityType: 'canvas_page',
    publishedVersion: true,
  });

  useEffect(() => {
    setDraftLayoutSnapshot((currentSnapshot) => currentSnapshot ?? draftLayout);
  }, [draftLayout]);

  useEffect(() => {
    setPublishedLayoutSnapshot(
      (currentSnapshot) => currentSnapshot ?? publishedLayout,
    );
  }, [publishedLayout]);

  const stableDraftLayout = draftLayoutSnapshot ?? draftLayout;
  const stablePublishedLayout = publishedLayoutSnapshot ?? publishedLayout;

  if (
    (!stableDraftLayout && isDraftError) ||
    (!stablePublishedLayout && isPublishedError)
  ) {
    return (
      <Flex
        align="center"
        justify="center"
        height="100%"
        data-testid="conflict-preview-error"
      >
        <Text color="red">
          Unable to load both versions of this page for comparison.
        </Text>
      </Flex>
    );
  }

  return (
    <PageConflictComparisonView
      entityId={entityId}
      entityType={entityType}
      publishedVersion={{
        html: stablePublishedLayout?.html || EMPTY_HTML,
        props: {
          entity_form_fields: stablePublishedLayout?.entity_form_fields ?? {},
          layout: stablePublishedLayout?.layout ?? [],
          model: stablePublishedLayout?.model ?? {},
        },
        loading: !stablePublishedLayout && isPublishedLoading,
        updated: formatConflictVersionUpdated(stablePublishedLayout?.updated),
      }}
      newVersion={{
        html: stableDraftLayout?.html || EMPTY_HTML,
        props: {
          entity_form_fields: stableDraftLayout?.entity_form_fields ?? {},
          layout: stableDraftLayout?.layout ?? [],
          model: stableDraftLayout?.model ?? {},
        },
        loading: !stableDraftLayout && isDraftLoading,
        changed: true,
        updated: formatConflictVersionUpdated(
          stableDraftLayout?.updated ?? autoSaveUpdated,
        ),
      }}
      selectedVersion={selectedVersion}
      onSelectVersion={onSelectVersion}
    />
  );
};

const getReviewChangesButtonText = (count: number): string => {
  if (count === 1) {
    return 'Review 1 change';
  }
  return `Review ${count} changes`;
};

const ConflictResolutionLoadingState = () => (
  <div className={styles.page} data-testid="conflict-resolution-loading-state">
    <div className={styles.header}>
      <Flex align="center" gap="1">
        <Text size="1" color="blue">
          Canvas
        </Text>
        <Text size="1" color="gray">
          /
        </Text>
        <Text size="1">Conflict</Text>
      </Flex>
    </div>
    <Flex
      direction="column"
      align="center"
      justify="center"
      className={styles.emptyState}
      gap="3"
    >
      <Spinner />
      <Text size="2">Loading pending changes</Text>
    </Flex>
  </div>
);

const ResolvedConflictState = ({
  pendingChangesCount,
  onReviewChanges,
  onClose,
}: {
  pendingChangesCount: number;
  onReviewChanges: () => void;
  onClose: () => void;
}) => (
  <div className={styles.page} data-testid="conflict-resolved-state">
    <div className={styles.header}>
      <Flex align="center" gap="1">
        <Text size="1" color="blue">
          Canvas
        </Text>
        <Text size="1" color="gray">
          /
        </Text>
        <Text size="1">Conflict</Text>
        <Text size="1" color="gray">
          /
        </Text>
        <Text size="1">All files resolved</Text>
      </Flex>
      <IconButton
        variant="ghost"
        color="gray"
        highContrast
        aria-label="Close"
        onClick={onClose}
      >
        <Cross2Icon />
      </IconButton>
    </div>
    <Flex
      direction="column"
      align="center"
      justify="center"
      className={styles.emptyState}
      gap="3"
    >
      <CheckCircledIcon className={styles.emptyIcon} />
      <Text size="4" weight="medium">
        Everything is up to date
      </Text>
      <Text size="2">
        All conflicts have been resolved. Your changes are ready to be
        published.
      </Text>
      <Flex align="center" gap="2">
        <Button onClick={onReviewChanges} disabled={pendingChangesCount === 0}>
          {getReviewChangesButtonText(pendingChangesCount)}
        </Button>
        <Button variant="outline" onClick={onClose}>
          Close
        </Button>
      </Flex>
    </Flex>
  </div>
);

export default ConflictResolutionPage;
