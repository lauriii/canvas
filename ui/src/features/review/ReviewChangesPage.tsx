import { useEffect, useMemo, useState } from 'react';
import {
  Navigate,
  useLocation,
  useNavigate,
  useParams,
} from 'react-router-dom';

import { useAppDispatch } from '@/app/hooks';
import { usePublishPendingChanges } from '@/components/review/usePublishPendingChanges';
import { isConflictUxEnabled } from '@/features/conflict/conflictUtils';
import {
  findReviewIndex,
  getReviewQueue,
  getReviewRouteFromEntry,
  getReviewRouteStatePointers,
} from '@/features/review/reviewChanges';
import {
  ReviewChangesView,
  ReviewCompleteState,
  ReviewLoadingState,
} from '@/features/review/ReviewChangesView';
import { unsetActivePanel } from '@/features/ui/primaryPanelSlice';
import { PageVersionComparison } from '@/features/versionComparison/PageVersionComparison';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import { contentApi } from '@/services/content';
import {
  useDiscardPendingChangeMutation,
  useGetAllPendingChangesQuery,
} from '@/services/pendingChangesApi';

import type { PageVersionSelection } from '@/features/versionComparison/PageVersionComparisonView';
import type {
  PendingChange,
  PendingChanges,
} from '@/services/pendingChangesApi';
import type { UnpublishedChange } from '@/types/Review';

const getPendingChangeFromEntry = (
  pointer: string,
  change: PendingChange,
): UnpublishedChange => ({
  ...change,
  pointer,
});

const ReviewChangesPage = () =>
  isConflictUxEnabled() ? (
    <EnabledReviewChangesPage />
  ) : (
    <Navigate to="/editor" replace />
  );

const EnabledReviewChangesPage = () => {
  const { entityType, entityId } = useParams<{
    entityType?: string;
    entityId?: string;
  }>();
  const location = useLocation();
  const navigate = useNavigate();
  const dispatch = useAppDispatch();
  const {
    data: pendingChanges,
    isFetching,
    refetch,
  } = useGetAllPendingChangesQuery(undefined, {
    refetchOnMountOrArgChange: true,
  });
  const [publishPendingChanges, { isLoading: isPublishing }] =
    usePublishPendingChanges();
  const [discardChange, { isLoading: isDiscarding }] =
    useDiscardPendingChangeMutation();
  const routeState = useMemo(
    () => getReviewRouteStatePointers(location.state),
    [location.state],
  );
  const [versionSelections, setVersionSelections] = useState<
    Record<string, Exclude<PageVersionSelection, undefined>>
  >({});
  const [selectedPointers, setSelectedPointers] = useState<string[]>(
    () => routeState.selectedPointers ?? [],
  );
  const [hasInitializedSelection, setHasInitializedSelection] = useState(
    () => !!routeState.selectedPointers,
  );
  const [pendingChangesSnapshot, setPendingChangesSnapshot] =
    useState<PendingChanges>();

  useEffect(() => {
    dispatch(unsetActivePanel());
  }, [dispatch]);

  useEffect(() => {
    // Keep the active review stable. New pending changes should appear only
    // after leaving and reopening the review page.
    if (
      pendingChangesSnapshot !== undefined ||
      isFetching ||
      pendingChanges === undefined
    ) {
      return;
    }
    setPendingChangesSnapshot(pendingChanges);
  }, [isFetching, pendingChanges, pendingChangesSnapshot]);

  const reviewQueue = useMemo(
    () => getReviewQueue(pendingChangesSnapshot, routeState.reviewPointers),
    [pendingChangesSnapshot, routeState.reviewPointers],
  );
  const reviewPointers = useMemo(
    () => reviewQueue.map(({ pointer }) => pointer),
    [reviewQueue],
  );
  const defaultSelectedPointers = useMemo(
    () =>
      routeState.selectedPointers ?? reviewQueue.map(({ pointer }) => pointer),
    [reviewQueue, routeState.selectedPointers],
  );

  useEffect(() => {
    if (hasInitializedSelection || pendingChangesSnapshot === undefined) {
      return;
    }
    setSelectedPointers(defaultSelectedPointers);
    setHasInitializedSelection(true);
  }, [
    defaultSelectedPointers,
    hasInitializedSelection,
    pendingChangesSnapshot,
  ]);

  const selectedPointerSet = useMemo(
    () => new Set(selectedPointers),
    [selectedPointers],
  );
  const selectedChanges = useMemo(
    () =>
      selectedPointers.flatMap((pointer) => {
        const change = pendingChangesSnapshot?.[pointer];
        return change && !change.hasConflict
          ? [getPendingChangeFromEntry(pointer, change)]
          : [];
      }),
    [pendingChangesSnapshot, selectedPointers],
  );

  const currentIndex = findReviewIndex(reviewQueue, entityType, entityId);
  const currentEntry =
    currentIndex >= 0 ? reviewQueue[currentIndex] : undefined;
  const navigationSelectedPointers = hasInitializedSelection
    ? selectedPointers
    : defaultSelectedPointers;
  const routeStateForNavigation = {
    selectedPointers: navigationSelectedPointers,
    reviewPointers,
  };
  const navigateToReviewOverview = () => {
    navigate('/review', {
      state: routeStateForNavigation,
    });
  };
  const navigateToReviewEntry = (entry: (typeof reviewQueue)[number]) => {
    navigate(getReviewRouteFromEntry(entry), {
      state: routeStateForNavigation,
    });
  };
  const reviewComplete =
    routeState.reviewComplete ||
    (!entityType && !entityId && reviewQueue.length === 0);

  if (pendingChangesSnapshot === undefined) {
    return (
      <ReviewLoadingState
        onClose={() => navigate('/editor')}
        onNavigateToCanvas={() => navigate('/editor')}
        onNavigateToReview={() => navigate('/review')}
      />
    );
  }

  if (!entityType || !entityId) {
    if (reviewComplete || !reviewQueue.length) {
      const previousEntry = reviewQueue[reviewQueue.length - 1];
      return (
        <ReviewCompleteState
          selectedCount={selectedChanges.length}
          isPublishing={isPublishing}
          onPublish={async () => {
            const didPublish = await publishPendingChanges(selectedChanges);
            if (didPublish) {
              navigate('/editor');
            }
          }}
          onClose={() => navigate('/editor')}
          onPrevious={() =>
            previousEntry && navigateToReviewEntry(previousEntry)
          }
          canPrevious={!!previousEntry}
          onNavigateToCanvas={() => navigate('/editor')}
          onNavigateToReview={navigateToReviewOverview}
        />
      );
    }

    return (
      <Navigate
        to={getReviewRouteFromEntry(reviewQueue[0])}
        replace
        state={{
          selectedPointers: navigationSelectedPointers,
          reviewPointers,
        }}
      />
    );
  }

  if (!currentEntry) {
    return reviewQueue.length > 0 ? (
      <Navigate
        to={getReviewRouteFromEntry(reviewQueue[0])}
        replace
        state={{
          selectedPointers: navigationSelectedPointers,
          reviewPointers,
        }}
      />
    ) : (
      <Navigate
        to="/review"
        replace
        state={{
          selectedPointers,
          reviewComplete: true,
        }}
      />
    );
  }

  const activeChange = currentEntry.change;
  const activePointer = currentEntry.pointer;
  const activeDraftVersionKey = `${activeChange.data_hash}:${activeChange.updated}`;
  const selectedVersion =
    versionSelections[activePointer] ??
    (selectedPointerSet.has(activePointer) ? 'new' : undefined);
  const isSelectedForPublishing = selectedVersion === 'new';
  const isApplyingSelection = isPublishing || isDiscarding;

  const setActiveVersionSelection = (
    version: Exclude<PageVersionSelection, undefined>,
  ) => {
    setVersionSelections((currentSelections) => ({
      ...currentSelections,
      [activePointer]: version,
    }));
    setSelectedPointers((currentPointers) => {
      if (version === 'new') {
        return currentPointers.includes(activePointer)
          ? currentPointers
          : [...currentPointers, activePointer];
      }
      return currentPointers.filter((pointer) => pointer !== activePointer);
    });
  };

  const handleSelectedForPublishingChange = (checked: boolean) => {
    setActiveVersionSelection(checked ? 'new' : 'published');
  };

  const navigateAfterCurrentReview = (nextSelectedPointers: string[]) => {
    const nextEntry = reviewQueue[currentIndex + 1];
    if (nextEntry) {
      navigate(getReviewRouteFromEntry(nextEntry), {
        state: {
          ...routeStateForNavigation,
          selectedPointers: nextSelectedPointers,
        },
      });
      return;
    }

    navigate('/review', {
      state: {
        ...routeStateForNavigation,
        selectedPointers: nextSelectedPointers,
        reviewComplete: true,
      },
    });
  };

  const handleNext = () => {
    navigateAfterCurrentReview(selectedPointers);
  };

  const handleApplyVersionSelection = async () => {
    if (selectedVersion === 'published') {
      const nextSelectedPointers = selectedPointers.filter(
        (pointer) => pointer !== activePointer,
      );
      try {
        await discardChange({
          ...activeChange,
          pointer: activePointer,
        }).unwrap();
        dispatch(
          componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]),
        );
        dispatch(
          contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]),
        );
        setSelectedPointers(nextSelectedPointers);
        await refetch();
        navigateAfterCurrentReview(nextSelectedPointers);
      } catch {
        // Error state is handled in pendingChangesApi.discardPendingChange.
      }
      return;
    }

    if (selectedVersion === 'new') {
      const didPublish = await publishPendingChanges(selectedChanges);
      if (didPublish) {
        navigate('/editor');
      }
    }
  };

  return (
    <ReviewChangesView
      label={activeChange.label}
      comparison={
        <PageVersionComparison
          key={`${activePointer}:${activeDraftVersionKey}`}
          entityId={String(activeChange.entity_id)}
          entityType={activeChange.entity_type}
          autoSaveUpdated={activeChange.updated}
          draftVersionKey={activeDraftVersionKey}
          publishedVersionTitle={Drupal.t('Old version')}
          newVersionTitle={Drupal.t('New version')}
          selectedVersion={selectedVersion}
          onSelectVersion={setActiveVersionSelection}
          onNewEditClick={() =>
            navigate(
              `/editor/${activeChange.entity_type}/${activeChange.entity_id}`,
            )
          }
        />
      }
      onClose={() =>
        navigate(
          `/editor/${activeChange.entity_type}/${activeChange.entity_id}`,
        )
      }
      onNavigateToCanvas={() => navigate('/editor')}
      onNavigateToReview={navigateToReviewOverview}
      reviewIndex={currentIndex}
      reviewTotal={reviewQueue.length}
      selectedVersion={selectedVersion}
      isSelectedForPublishing={isSelectedForPublishing}
      isApplyingSelection={isApplyingSelection}
      canPublish={selectedChanges.length > 0}
      onSelectedForPublishingChange={handleSelectedForPublishingChange}
      onPrevious={() => navigateToReviewEntry(reviewQueue[currentIndex - 1])}
      onNext={handleNext}
      onApplyVersionSelection={handleApplyVersionSelection}
      canPrevious={currentIndex > 0}
    />
  );
};

export default ReviewChangesPage;
