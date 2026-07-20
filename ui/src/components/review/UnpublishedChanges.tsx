import { useCallback, useEffect, useMemo, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useLocation, useNavigate, useParams } from 'react-router';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import PublishReview from '@/components/review/PublishReview';
import {
  selectConflicts,
  selectErrors,
  selectPreviousPendingChanges,
  setConflicts,
} from '@/components/review/PublishReview.slice';
import { usePublishPendingChanges } from '@/components/review/usePublishPendingChanges';
import {
  resetCodeEditor,
  setForceRefresh,
} from '@/features/code-editor/codeEditorSlice';
import {
  getConflictRouteForChange,
  isConflictUxEnabled,
} from '@/features/conflict/conflictUtils';
import { FORM_TYPES } from '@/features/form/constants';
import { clearFieldValues } from '@/features/form/formStateSlice';
import { setInitialized } from '@/features/layout/layoutModelSlice';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import {
  getReviewRouteForChange,
  isReviewableChange,
} from '@/features/review/reviewChanges';
import {
  clearSelection,
  selectSelectedComponentUuid,
} from '@/features/ui/uiSlice';
import { brandKitApi } from '@/services/brandKit';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import { contentApi, useGetContentListQuery } from '@/services/content';
import {
  useDiscardPendingChangeMutation,
  useGetAllPendingChangesQuery,
} from '@/services/pendingChangesApi';
import {
  useQueuedPostPreviewMutation,
  useUpdateComponentMutation,
} from '@/services/preview';

import type { UnpublishedChange } from '@/types/Review';

const REFETCH_INTERVAL_MS = 10000;
const REVIEW_CHANGES_QUERY_PARAM = 'reviewChanges';

const UnpublishedChanges = () => {
  const previousPendingChanges = useAppSelector(selectPreviousPendingChanges);
  const conflicts = useAppSelector(selectConflicts);
  const errorResponse = useAppSelector(selectErrors);
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const [discardChange, { isLoading: isDiscarding }] =
    useDiscardPendingChangeMutation();
  const [, { isLoading: isUpdatingComponent }] = useUpdateComponentMutation({
    fixedCacheKey: selectedComponent,
  });
  const [, { isLoading: isUpdatingPreview }] = useQueuedPostPreviewMutation({
    fixedCacheKey: 'editorFramePreview',
  });
  const [pollingInterval, setPollingInterval] =
    useState<number>(REFETCH_INTERVAL_MS);
  const [reviewOpen, setReviewOpen] = useState(false);
  const {
    data: changes,
    error,
    refetch,
    isFetching,
  } = useGetAllPendingChangesQuery(undefined, {
    pollingInterval: pollingInterval,
    skipPollingIfUnfocused: true,
  });
  const { entityType, entityId, codeComponentId } = useParams();
  const location = useLocation();
  const navigate = useNavigate();
  const dispatch = useAppDispatch();
  const { showBoundary } = useErrorBoundary();
  const entity_form_fields = useAppSelector(selectPageData);
  const [publishPendingChanges, { isLoading: isPublishing }] =
    usePublishPendingChanges({
      currentEntityId: entityId,
      currentEntityType: entityType,
      entityFormFields: entity_form_fields,
    });
  // Fetch content list to get status information for all pages
  const { data: contentListData } = useGetContentListQuery({
    entityType: 'canvas_page',
  });
  const pageItems = contentListData?.items;
  const conflictUxEnabled = isConflictUxEnabled();

  // If either the selected component or the preview layout is being updated, disable the Publish button.
  const isUpdating = isUpdatingComponent || isUpdatingPreview;

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  const unpublishedChanges: UnpublishedChange[] = useMemo(
    () =>
      Object.entries(changes || {})
        .map(([pointer, change]) => ({
          ...change,
          pointer,
        }))
        .sort((a, b) => b.updated - a.updated),
    [changes],
  );

  useEffect(() => {
    if (previousPendingChanges) refetch();
  }, [previousPendingChanges, refetch]);

  const onOpenChangeHandler = useCallback(
    (open: boolean): void => {
      setReviewOpen(open);
      if (open) {
        setPollingInterval(0);
        refetch();
      } else {
        setPollingInterval(REFETCH_INTERVAL_MS);
      }
    },
    [refetch],
  );

  useEffect(() => {
    const searchParams = new URLSearchParams(location.search);
    if (searchParams.get(REVIEW_CHANGES_QUERY_PARAM) !== '1') {
      return;
    }

    onOpenChangeHandler(true);
    searchParams.delete(REVIEW_CHANGES_QUERY_PARAM);
    navigate(
      {
        pathname: location.pathname,
        search: searchParams.toString() ? `?${searchParams.toString()}` : '',
        hash: location.hash,
      },
      { replace: true },
    );
  }, [
    location.hash,
    location.pathname,
    location.search,
    navigate,
    onOpenChangeHandler,
  ]);

  const onPublishClick = async (selectedChanges: UnpublishedChange[]) => {
    await publishPendingChanges(selectedChanges);
  };

  const navigateToReview = (selectedChanges: UnpublishedChange[]) => {
    if (!conflictUxEnabled) {
      return;
    }
    const reviewableChanges = selectedChanges.filter(isReviewableChange);
    const firstReviewableChange = reviewableChanges[0];
    if (!firstReviewableChange) {
      return;
    }
    navigate(getReviewRouteForChange(firstReviewableChange), {
      state: {
        selectedPointers: selectedChanges.map((change) => change.pointer),
        reviewPointers: reviewableChanges.map((change) => change.pointer),
      },
    });
  };

  const onDiscardClick = async (selectedChange: UnpublishedChange) => {
    if (!selectedChange) return;

    try {
      await discardChange(selectedChange).unwrap();
      const remainingConflicts = conflicts?.filter(
        (conflict) => conflict.source.pointer !== selectedChange.pointer,
      );
      dispatch(
        setConflicts(
          remainingConflicts?.length ? remainingConflicts : undefined,
        ),
      );
      // After discarding, refresh the editor state from canonical server data.
      dispatch(componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]));
      dispatch(
        contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]),
      );
      if (selectedChange.entity_type === 'js_component') {
        const discardedCodeComponentId = String(selectedChange.entity_id);
        dispatch(
          componentAndLayoutApi.util.invalidateTags([
            { type: 'CodeComponents', id: discardedCodeComponentId },
            { type: 'CodeComponentAutoSave', id: discardedCodeComponentId },
          ]),
        );
        // If the code editor is open for this component, force it to refetch
        // and re-initialize with the canonical (published) data.
        if (codeComponentId && codeComponentId === discardedCodeComponentId) {
          dispatch(setForceRefresh(true));
          dispatch(resetCodeEditor());
        }
      }
      if (selectedChange.entity_type === 'brand_kit') {
        const discardedBrandKitId = String(selectedChange.entity_id);
        dispatch(
          brandKitApi.util.invalidateTags([
            { type: 'BrandKits', id: discardedBrandKitId },
            { type: 'BrandKitsAutoSave', id: discardedBrandKitId },
            { type: 'BrandKits', id: 'LIST' },
          ]),
        );
      }
      // When the discarded change is for the current page, re-apply the
      // refetched layout and model so the canvas, sidebar, and form fields
      // show the published state instead of stale discarded values.
      const isCurrentPage =
        entityId &&
        entityType &&
        selectedChange.entity_type === entityType &&
        String(selectedChange.entity_id) === entityId;
      if (isCurrentPage) {
        dispatch(setInitialized(false));
        // Clear cached form values so the props and entity forms reflect the
        // refetched layout and model.
        dispatch(clearFieldValues(FORM_TYPES.COMPONENT_INSTANCE_FORM));
        dispatch(clearFieldValues(FORM_TYPES.ENTITY_FORM));
        dispatch(clearSelection());
      }
      refetch();
    } catch {
      // Error state is handled in pendingChangesApi.discardPendingChange.
    }
  };

  const conflictCount = useMemo(
    () =>
      conflictUxEnabled
        ? unpublishedChanges.filter((change) => change.hasConflict).length
        : 0,
    [conflictUxEnabled, unpublishedChanges],
  );

  // Create a map of entity_id -> { status, isNew, hasUnsavedStatusChange } for quick lookup
  const pageStatusMap = useMemo(() => {
    if (!pageItems) return {};
    const map: Record<
      string,
      { status: boolean; isNew?: boolean; hasUnsavedStatusChange?: boolean }
    > = {};
    pageItems.forEach((page) => {
      map[String(page.id)] = {
        status: page.status,
        isNew: page.isNew,
        hasUnsavedStatusChange: page.hasUnsavedStatusChange,
      };
    });
    return map;
  }, [pageItems]);

  return (
    <PublishReview
      open={reviewOpen}
      isUpdating={isUpdating}
      isFetching={isFetching}
      changes={unpublishedChanges}
      conflictCount={conflictCount}
      errors={errorResponse}
      onOpenChangeCallback={onOpenChangeHandler}
      onPublishClick={onPublishClick}
      onDiscardClick={onDiscardClick}
      onReviewSelectedChanges={conflictUxEnabled ? navigateToReview : undefined}
      onViewClick={
        conflictUxEnabled ? (change) => navigateToReview([change]) : undefined
      }
      isViewChangeAvailable={conflictUxEnabled ? isReviewableChange : undefined}
      onResolveConflict={(change) => {
        if (change && conflictUxEnabled) {
          navigate(getConflictRouteForChange(change));
          return;
        }
        refetch();
      }}
      isPublishing={isPublishing}
      isDiscarding={isDiscarding}
      pageStatusMap={pageStatusMap}
    />
  );
};

export default UnpublishedChanges;
