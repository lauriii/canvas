import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useParams, useSearchParams } from 'react-router';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectPageDataOwner } from '@/features/pageData/pageDataSlice';
import { clearSelection } from '@/features/ui/uiSlice';
import { useUndoRedo } from '@/hooks/useUndoRedo';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';

import {
  selectIsInitialized,
  setInitialized,
  setInitialLayoutModel,
} from './layoutModelSlice';

const LayoutLoader = () => {
  const dispatch = useAppDispatch();
  const isInitialized = useAppSelector(selectIsInitialized);
  const { entityId, entityType } = useParams();
  // The forked translation being edited, if any. The base query names it on
  // every layout request, so switching it loads a different layout.
  // @see \Drupal\canvas\EventSubscriber\ForkedTranslationEditRequestSubscriber
  const [searchParams] = useSearchParams();
  const editorLanguage = searchParams.get('language') ?? '';
  const pageDataOwner = useAppSelector(selectPageDataOwner);

  // Switching the translation of the entity open in the editor reloads the
  // app, so its layout, page data, and undo history are never mixed across
  // translations, not even for one render.
  // ponytail: a full reload; switch in place if reloads become a UX problem.
  useEffect(() => {
    if (
      pageDataOwner?.entityType === entityType &&
      pageDataOwner?.entityId === entityId &&
      (pageDataOwner?.langcode ?? '') !== editorLanguage
    ) {
      window.location.reload();
    }
  }, [pageDataOwner, entityType, entityId, editorLanguage]);

  const {
    data: fetchedLayout,
    error,
    isError,
    isFetching,
    refetch,
  } = useGetPageLayoutQuery(
    entityId && entityType ? { entityId, entityType } : skipToken,
    // Setting `refetchOnMountOrArgChange` instead of a cache invalidation
    // prevents re-fetching due to the same query being used elsewhere in the app.
    { refetchOnMountOrArgChange: true },
  );

  const { showBoundary, resetBoundary } = useErrorBoundary();
  const { dispatchClearUndoRedoHistory } = useUndoRedo();

  useEffect(() => {
    dispatch(setInitialized(false));
    dispatch(clearSelection());
    if (entityId && entityType) {
      refetch();
    }
  }, [refetch, entityType, entityId, editorLanguage, dispatch]);

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

    if (fetchedLayout && !isInitialized && !isFetching) {
      dispatchClearUndoRedoHistory();
      dispatch(
        setInitialLayoutModel({
          layout: fetchedLayout.layout,
          model: fetchedLayout.model,
          translations: fetchedLayout.translations || {},
          // We don't need to update the preview here - it is done in the layout
          // api's onQueryStarted method - @see componentAndLayout.ts
          updatePreview: false,
        }),
      );
    }
  }, [
    fetchedLayout,
    isInitialized,
    error,
    showBoundary,
    dispatch,
    resetBoundary,
    isError,
    isFetching,
    dispatchClearUndoRedoHistory,
  ]);

  return null;
};

export default LayoutLoader;
