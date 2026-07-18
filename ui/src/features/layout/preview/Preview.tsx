import { useCallback, useEffect, useRef } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
// eslint-disable-next-line @typescript-eslint/no-restricted-imports
import { useStore } from 'react-redux';
import { useParams } from 'react-router';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectLayout,
  selectModel,
  selectUpdatePreview,
} from '@/features/layout/layoutModelSlice';
import { initAutoSavePersister } from '@/features/layout/preview/autoSavePersister';
import ComponentHtmlMapProvider from '@/features/layout/preview/DataToHtmlMapContext';
import HeadlessPreview from '@/features/layout/preview/HeadlessPreview';
import { useHeadlessPreviewSettings } from '@/features/layout/preview/useHeadlessPreviewSettings';
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
import { useStableCallback } from '@/hooks/useStableCallback';
import useSyncTitle from '@/hooks/useSyncTitle';
import { usePostTemplateLayoutMutation } from '@/services/componentAndLayout';
import {
  selectUpdateComponentLoadingState,
  useOrderedPostPreviewMutation,
} from '@/services/preview';
import { isAjaxing } from '@/utils/isAjaxing';
import { getPreviewPerformanceFlags } from '@/utils/previewCadence';

import type React from 'react';

const Preview: React.FC = () => {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const updatePreview = useAppSelector(selectUpdatePreview);
  const model = useAppSelector(selectModel);
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const backgroundUpdate = useAppSelector(selectPreviewBackgroundUpdate);
  const entity_form_fields = useAppSelector(selectPageData);
  const needsPreviewAfterUndoRedo = useAppSelector(
    selectNeedsPreviewAfterUndoRedo,
  );
  const { entityId, entityType } = useParams();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const headlessSettings = useHeadlessPreviewSettings();
  const frameSrcDoc = useAppSelector(selectPreviewHtml);
  const { showBoundary } = useErrorBoundary();
  useSyncTitle();

  const pollingIntervalRef = useRef<ReturnType<typeof setInterval> | null>(
    null,
  );

  // --- API Mutations ---
  const [postPreview, { isLoading: isFetching }] =
    useOrderedPostPreviewMutation({
      fixedCacheKey: 'editorFramePreview',
    });

  const [postTemplatePreview, { isLoading: isTemplateFetching }] =
    usePostTemplateLayoutMutation({
      fixedCacheKey: 'editorFrameTemplatePreview',
    });
  const isPatching = useAppSelector((state) =>
    selectUpdateComponentLoadingState(state, selectedComponent),
  );

  const sendPreviewRequest = useCallback(
    async (context: 'entity' | 'template') => {
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
      entityId,
      entityType,
      postPreview,
      postTemplatePreview,
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
    (context: 'entity' | 'template') => {
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

      const context = editorFrameContext === 'template' ? 'template' : 'entity';
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

  // Decoupled auto-save: persist on exit events (blur, pagehide, tab-hide)
  // so half-finished work survives even though persistence is debounced.
  const store = useStore();
  useEffect(() => {
    if (!getPreviewPerformanceFlags().decoupledAutoSave) {
      return;
    }
    return initAutoSavePersister(store as any);
  }, [store]);

  // When the canvas_headless module embeds a frontend app, the app owns
  // the rendering: the srcdoc preview pipeline is bypassed, while the
  // mutation flow above keeps running so edits still persist to auto-save.
  if (headlessSettings) {
    return <HeadlessPreview settings={headlessSettings} />;
  }

  return (
    <ComponentHtmlMapProvider>
      <Viewport
        frameSrcDoc={frameSrcDoc}
        isFetching={
          (isFetching || isPatching || isTemplateFetching) && !backgroundUpdate
        }
      />
    </ComponentHtmlMapProvider>
  );
};
export default Preview;
