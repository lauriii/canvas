import { useEffect, useMemo } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useParams } from 'react-router';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { exposedSlotsFromServer } from '@/features/layout/exposedSlots';
import {
  useGetContentTemplatesQuery,
  useGetTemplateLayoutQuery,
} from '@/services/componentAndLayout';

import {
  selectIsInitialized,
  setInitialized,
  setInitialLayoutModel,
} from './layoutModelSlice';

const TemplateLayout = () => {
  const dispatch = useAppDispatch();
  const { entityType, bundle, viewMode, previewEntityId } = useParams();

  const isInitialized = useAppSelector(selectIsInitialized);
  const {
    data: fetchedLayout,
    error,
    isError,
    isFetching,
    refetch,
  } = useGetTemplateLayoutQuery(
    entityType && bundle && viewMode && previewEntityId
      ? { entityType, bundle, viewMode, previewEntityId }
      : skipToken,
    // Setting `refetchOnMountOrArgChange` instead of a cache invalidation
    // prevents re-fetching due to the same query being used elsewhere in the app.
    { refetchOnMountOrArgChange: true },
  );

  // The template layout GET does not (yet) emit the template's own exposed
  // slots, so fall back to the persisted set from the content-template config
  // list.
  // @todo Remove the fallback once ApiLayoutController::get() emits `exposedSlots` for the ContentTemplate branch.
  const { data: templates, isLoading: isTemplatesLoading } =
    useGetContentTemplatesQuery();
  const configExposedSlots = useMemo(() => {
    if (!entityType || !bundle || !viewMode) {
      return undefined;
    }
    return templates?.[entityType]?.bundles?.[bundle]?.viewModes?.[viewMode]
      ?.exposed_slots;
  }, [templates, entityType, bundle, viewMode]);

  const { showBoundary, resetBoundary } = useErrorBoundary();

  const { layout, model, translations, exposedSlots } = fetchedLayout || {};

  useEffect(() => {
    dispatch(setInitialized(false));
    if (entityType && bundle && viewMode && previewEntityId) {
      refetch();
    }
  }, [entityType, bundle, viewMode, previewEntityId, refetch, dispatch]);

  useEffect(() => {
    if (isError && error && !isFetching) {
      showBoundary(error);
      return;
    }
    // Reset the boundary so this component is re-rendered. Without this, the
    // error boundary will re-render while the layout is (re)fetching and as a
    // result it will require two clicks of the reset button in the alert to
    // allow the page to render.
    resetBoundary();

    // Wait for the fallback exposed-slot source before initializing so the
    // editor's working set is complete on first render.
    const exposedSlotsResolved =
      exposedSlots !== undefined || !isTemplatesLoading;

    if (
      layout &&
      model &&
      !isInitialized &&
      !isFetching &&
      exposedSlotsResolved
    ) {
      dispatch(
        setInitialLayoutModel({
          layout,
          model,
          translations: translations || {},
          // The template editor's working set of exposed slots. Omit
          // slotOverrides so this stays out of per-content mode.
          exposedSlots:
            exposedSlots ?? exposedSlotsFromServer(configExposedSlots),
          // We don't need to update the preview here - it is done in the layout
          // api's onQueryStarted method - @see componentAndLayout.ts
          updatePreview: false,
        }),
      );
    }
  }, [
    layout,
    model,
    translations,
    exposedSlots,
    configExposedSlots,
    isTemplatesLoading,
    isInitialized,
    error,
    showBoundary,
    dispatch,
    resetBoundary,
    isError,
    isFetching,
  ]);

  return null;
};

export default TemplateLayout;
