import { useEffect, useMemo, useRef, useState } from 'react';
import { useParams } from 'react-router';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { buildColorStyles } from '@/features/brandKit/colorCss';
import { BRAND_KIT_ID } from '@/features/brandKit/constants';
import IframeSwapper from '@/features/layout/preview/IframeSwapper';
import PreviewProgress from '@/features/layout/preview/PreviewProgress';
import InlineTextEditing from '@/features/layout/previewOverlay/inlineText/InlineTextEditing';
import ViewportOverlay from '@/features/layout/previewOverlay/ViewportOverlay';
import {
  EditorFrameMode,
  selectEditorFrameMode,
  selectIsInlineTextBusy,
  selectTextEditSession,
  selectViewportMinHeight,
  selectViewportWidth,
  setFirstLoadComplete,
  setPreviewLoading,
  unsetUpdatingComponent,
} from '@/features/ui/uiSlice';
import { useComponentHtmlMap } from '@/hooks/useComponentHtmlMap';
import useSyncIframeHeightToContent from '@/hooks/useSyncIframeHeightToContent';
import { useGetBrandKitQuery } from '@/services/brandKit';

import styles from './Preview.module.css';

/**
 * For non-full view modes (e.g. teaser, card), use no min height so the
 * viewport is exactly the content size. When the canvas is empty, the preview
 * iframe's shared empty-region placeholder stylesheet provides the height.
 */
const NON_FULL_VIEW_MODE_MIN_HEIGHT = 0;

export interface ViewportProps {
  isFetching: boolean;
  frameSrcDoc: string; // HTML as a string to be rendered in the iFrame
}

const Viewport: React.FC<ViewportProps> = (props) => {
  const { frameSrcDoc, isFetching } = props;
  const [isReloading, setIsReloading] = useState(true);
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const previewContainerRef = useRef<HTMLDivElement>(null);
  const dispatch = useAppDispatch();
  const editorFrameMode = useAppSelector(selectEditorFrameMode);
  const textEditSession = useAppSelector(selectTextEditSession);
  const isInlineTextBusy = useAppSelector(selectIsInlineTextBusy);
  const viewportWidth = useAppSelector(selectViewportWidth);
  const viewportMinHeight = useAppSelector(selectViewportMinHeight);
  const { viewMode } = useParams();
  const effectiveMinHeight =
    viewMode && viewMode !== 'full'
      ? NON_FULL_VIEW_MODE_MIN_HEIGHT
      : viewportMinHeight;
  useComponentHtmlMap(iframeRef.current);
  // Mirror live Brand kit colors to the preview. This subscription
  // ensures optimistic cache updates aren't lost when the editor panel
  // unmounts.
  const { data: brandKit } = useGetBrandKitQuery(BRAND_KIT_ID);
  const brandKitColorStyles = useMemo(
    () => buildColorStyles(brandKit?.colors),
    [brandKit?.colors],
  );

  useSyncIframeHeightToContent(
    iframeRef.current,
    previewContainerRef.current,
    effectiveMinHeight,
  );

  useEffect(() => {
    if (!isFetching && !isReloading) {
      dispatch(unsetUpdatingComponent());
    }
  }, [dispatch, isFetching, isReloading]);

  // Text edited in place waits for a loading document: the current one is
  // about to be swapped out.
  useEffect(() => {
    dispatch(setPreviewLoading(isReloading));
  }, [dispatch, isReloading]);

  useEffect(() => {
    const iframe = iframeRef.current;
    if (!iframe?.srcdoc || isReloading) {
      return;
    }

    iframe.dataset.testCanvasContentInitialized = 'true';
    dispatch(setFirstLoadComplete(true));
  }, [dispatch, isReloading]);

  const containerStyles = {
    width: `${viewportWidth}px`,
    minHeight: `${effectiveMinHeight}px`,
  };

  return (
    <div
      className={styles.previewContainer}
      ref={previewContainerRef}
      style={containerStyles}
    >
      <PreviewProgress loading={isFetching || isReloading} />
      <IframeSwapper
        ref={iframeRef}
        srcDocument={frameSrcDoc}
        headStyles={brandKitColorStyles}
        setIsReloading={setIsReloading}
        interactive={
          editorFrameMode === EditorFrameMode.INTERACTIVE || !!textEditSession
        }
        holdUpdates={isInlineTextBusy}
      />
      <InlineTextEditing />
      {editorFrameMode === EditorFrameMode.EDIT && (
        <ViewportOverlay previewContainerRef={previewContainerRef} />
      )}
    </div>
  );
};

export default Viewport;
