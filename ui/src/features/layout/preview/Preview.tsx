import { useCallback, useEffect, useRef } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useParams } from 'react-router';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectAutoSavesHash } from '@/components/review/PublishReview.slice';
import { exposedSlotsToServer } from '@/features/layout/exposedSlots';
import {
  selectExposedSlots,
  selectIsInitialized,
  selectLayout,
  selectModel,
  selectUpdatePreview,
} from '@/features/layout/layoutModelSlice';
import HeadlessPreview from '@/features/layout/preview/HeadlessPreview';
import { PreviewDomProvider } from '@/features/layout/preview/PreviewDomContext';
import { PreviewGeometryProvider } from '@/features/layout/preview/PreviewGeometryContext';
import Viewport from '@/features/layout/preview/Viewport';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import {
  selectPreviewBackgroundUpdate,
  selectPreviewHtml,
} from '@/features/pagePreview/previewSlice';
import {
  clearPreviewAfterUndoRedo,
  selectEditorFrameContext,
  selectNeedsPreviewAfterUndoRedo,
  selectSelectedComponentUuid,
} from '@/features/ui/uiSlice';
import { useCanvasHeadlessSettings } from '@/hooks/useCanvasHeadlessSettings';
import { useStableCallback } from '@/hooks/useStableCallback';
import useSyncTitle from '@/hooks/useSyncTitle';
import {
  useGetPageLayoutQuery,
  usePostPatternLayoutMutation,
  usePostTemplateLayoutMutation,
} from '@/services/componentAndLayout';
import {
  selectUpdateComponentLoadingState,
  useQueuedPostPreviewMutation,
} from '@/services/preview';
import { isAjaxing } from '@/utils/isAjaxing';

import type React from 'react';

const Preview: React.FC = () => {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const updatePreview = useAppSelector(selectUpdatePreview);
  const model = useAppSelector(selectModel);
  const exposedSlots = useAppSelector(selectExposedSlots);
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const backgroundUpdate = useAppSelector(selectPreviewBackgroundUpdate);
  const entity_form_fields = useAppSelector(selectPageData);
  const needsPreviewAfterUndoRedo = useAppSelector(
    selectNeedsPreviewAfterUndoRedo,
  );
  const { entityId, entityType } = useParams();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const headlessSettings = useCanvasHeadlessSettings();
  const frameSrcDoc = useAppSelector(selectPreviewHtml);
  const autoSavesHash = useAppSelector(selectAutoSavesHash);
  const { showBoundary } = useErrorBoundary();
  useSyncTitle();

  // Whether the model in the store belongs to the currently routed entity.
  // The loaders set this false the moment the route changes and true only once
  // the new entity's layout has loaded. A ref keeps it readable at request-send
  // time, including inside a parked poll whose closure was captured earlier.
  const isInitialized = useAppSelector(selectIsInitialized);
  const isInitializedRef = useRef(isInitialized);
  isInitializedRef.current = isInitialized;

  const pollingIntervalRef = useRef<ReturnType<typeof setInterval> | null>(
    null,
  );

  // --- API Mutations ---
  const [postPreview, { isLoading: isFetching }] = useQueuedPostPreviewMutation(
    {
      fixedCacheKey: 'editorFramePreview',
    },
  );

  const [postTemplatePreview, { isLoading: isTemplateFetching }] =
    usePostTemplateLayoutMutation({
      fixedCacheKey: 'editorFrameTemplatePreview',
    });

  const [postPatternPreview, { isLoading: isPatternFetching }] =
    usePostPatternLayoutMutation({
      fixedCacheKey: 'editorFramePatternPreview',
    });

  // While the layout of another entity loads (e.g. switching between a page
  // and a page variant), the previous preview stays visible with the loading
  // bar on top. Shares LayoutLoader's cache entry, so this adds no request.
  const { isFetching: isLayoutFetching } = useGetPageLayoutQuery(
    entityId && entityType ? { entityId, entityType } : skipToken,
  );
  const isPatching = useAppSelector((state) =>
    selectUpdateComponentLoadingState(state, selectedComponent),
  );

  const sendPreviewRequest = useCallback(
    async (context: 'entity' | 'template' | 'pattern') => {
      // The layout/model come from a store shared across entities and are not
      // cleared on navigation, while the request target is derived from the
      // current route. If the routed entity's own layout has not loaded yet,
      // the store still holds the previously edited entity's model; persisting
      // it now would write that entity's content onto the one just navigated
      // to. Skip until the current entity is initialized. This also cancels any
      // request that was parked (behind an in-flight AJAX) before navigation.
      if (!isInitializedRef.current) {
        return;
      }
      try {
        // Execute Request
        if (context === 'entity' && entityId && entityType) {
          await postPreview({
            layout,
            model,
            entity_form_fields,
            entityId,
            entityType,
          });
        } else if (context === 'template') {
          await postTemplatePreview({
            layout,
            model,
            entity_form_fields,
            // Persist the exposed-slot working set alongside the layout.
            exposed_slots: exposedSlotsToServer(exposedSlots),
          }).unwrap();
        } else if (context === 'pattern') {
          await postPatternPreview({
            layout,
            model,
            entity_form_fields,
          }).unwrap();
        }
      } catch (err) {
        showBoundary(err);
      }
    },
    [
      layout,
      model,
      entity_form_fields,
      exposedSlots,
      entityId,
      entityType,
      postPreview,
      postTemplatePreview,
      postPatternPreview,
      showBoundary,
    ],
  );

  /**
   * STABLE WRAPPER:
   * This function identity never changes, but it always "sees" the latest
   * sendPreviewRequest closure. This allows us to use it in useEffect
   * without triggering the effect when layout/model changes.
   */
  const stableScheduleRequest = useStableCallback(
    (context: 'entity' | 'template' | 'pattern') => {
      // Clear any existing polling to avoid double-requests
      if (pollingIntervalRef.current) {
        clearInterval(pollingIntervalRef.current);
        pollingIntervalRef.current = null;
      }

      if (isAjaxing()) {
        pollingIntervalRef.current = setInterval(() => {
          if (!isAjaxing()) {
            if (pollingIntervalRef.current) {
              clearInterval(pollingIntervalRef.current);
              pollingIntervalRef.current = null;
            }
            sendPreviewRequest(context);
          }
        }, 50);
      } else {
        sendPreviewRequest(context);
      }
    },
  );

  // Effect: Trigger POSTing of layout, model and entity_form_fields when they change
  // to generate a new preview and create a new autoSave.
  useEffect(() => {
    if (updatePreview || needsPreviewAfterUndoRedo) {
      // If we're triggering because of undo/redo flag, clear it immediately
      if (needsPreviewAfterUndoRedo) {
        dispatch(clearPreviewAfterUndoRedo());
      }

      const context =
        editorFrameContext === 'template'
          ? 'template'
          : editorFrameContext === 'pattern'
            ? 'pattern'
            : 'entity';
      stableScheduleRequest(context);
    }
  }, [
    layout,
    model,
    entity_form_fields,
    updatePreview,
    needsPreviewAfterUndoRedo,
    editorFrameContext,
    stableScheduleRequest,
    dispatch,
  ]);

  // Effect: Cleanup interval on unmount
  useEffect(() => {
    return () => {
      if (pollingIntervalRef.current) {
        clearInterval(pollingIntervalRef.current);
        pollingIntervalRef.current = null;
      }
    };
  }, []);

  // When the canvas_headless module embeds a frontend app, the app owns
  // the rendering: the srcdoc preview pipeline is bypassed, while the
  // mutation flow above keeps running so edits still persist to auto-save.
  return (
    <PreviewGeometryProvider>
      {headlessSettings ? (
        <HeadlessPreview
          settings={headlessSettings}
          autoSavesHash={autoSavesHash}
        />
      ) : (
        <PreviewDomProvider>
          <Viewport
            frameSrcDoc={frameSrcDoc}
            isFetching={
              (isFetching ||
                isPatching ||
                isTemplateFetching ||
                isPatternFetching ||
                isLayoutFetching) &&
              !backgroundUpdate
            }
          />
        </PreviewDomProvider>
      )}
    </PreviewGeometryProvider>
  );
};
export default Preview;
