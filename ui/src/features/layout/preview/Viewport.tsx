import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router';
import { Progress } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import IframeSwapper from '@/features/layout/preview/IframeSwapper';
import { RegionSpotlight } from '@/features/layout/preview/RegionSpotlight/RegionSpotlight';
import ViewportOverlay from '@/features/layout/previewOverlay/ViewportOverlay';
import {
  EditorFrameMode,
  selectEditorFrameMode,
  selectViewportMinHeight,
  selectViewportWidth,
  setFirstLoadComplete,
  unsetUpdatingComponent,
} from '@/features/ui/uiSlice';
import { useComponentHtmlMap } from '@/hooks/useComponentHtmlMap';
import useSyncIframeHeightToContent from '@/hooks/useSyncIframeHeightToContent';

import styles from './Preview.module.css';

/**
 * For non-full view modes (e.g. teaser, card), use no min height so the
 * viewport is exactly the content size. When the canvas is empty, the preview
 * iframe's empty-region placeholder (see preview.css) provides the height.
 */
const NON_FULL_VIEW_MODE_MIN_HEIGHT = 0;

export interface ViewportProps {
  isFetching: boolean;
  frameSrcDoc: string; // HTML as a string to be rendered in the iFrame
}

const Viewport: React.FC<ViewportProps> = (props) => {
  const { frameSrcDoc, isFetching } = props;
  const [isReloading, setIsReloading] = useState(true);
  const [showProgressIndicator, setShowProgressIndicator] = useState(false);
  const progressTimerRef = useRef<number | null>();
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const previewContainerRef = useRef<HTMLDivElement>(null);
  const dispatch = useAppDispatch();
  const editorFrameMode = useAppSelector(selectEditorFrameMode);
  const viewportWidth = useAppSelector(selectViewportWidth);
  const viewportMinHeight = useAppSelector(selectViewportMinHeight);
  const { viewMode } = useParams();
  const effectiveMinHeight =
    viewMode && viewMode !== 'full'
      ? NON_FULL_VIEW_MODE_MIN_HEIGHT
      : viewportMinHeight;
  useComponentHtmlMap(iframeRef.current);

  useSyncIframeHeightToContent(
    iframeRef.current,
    previewContainerRef.current,
    effectiveMinHeight,
  );

  useEffect(() => {
    if (isFetching || isReloading) {
      progressTimerRef.current = window.setTimeout(() => {
        setShowProgressIndicator(true);
      }, 500); // Delay progress appearance by 500ms to avoid showing unless the user is actually waiting.
    }
    if (!isFetching && !isReloading) {
      if (progressTimerRef.current) {
        clearTimeout(progressTimerRef.current);
      }
      setShowProgressIndicator(false);
      dispatch(unsetUpdatingComponent());
    }
    return () => {
      if (progressTimerRef.current) {
        clearTimeout(progressTimerRef.current);
      }
    };
  }, [dispatch, isFetching, isReloading]);

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
      {showProgressIndicator && (
        <>
          <Progress
            aria-label="Loading Preview"
            className={styles.progress}
            duration="1s"
          />
        </>
      )}
      <IframeSwapper
        ref={iframeRef}
        srcDocument={frameSrcDoc}
        setIsReloading={setIsReloading}
        interactive={editorFrameMode === EditorFrameMode.INTERACTIVE}
      />
      {editorFrameMode === EditorFrameMode.EDIT && (
        <>
          <ViewportOverlay
            iframeRef={iframeRef}
            previewContainerRef={previewContainerRef}
          />
          <RegionSpotlight />
        </>
      )}
    </div>
  );
};

export default Viewport;
