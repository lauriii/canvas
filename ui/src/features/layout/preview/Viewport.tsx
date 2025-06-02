import styles from './Preview.module.css';
import { useRef, useEffect, useState } from 'react';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { Progress } from '@radix-ui/themes';
import {
  CanvasMode,
  selectCanvasMode,
  selectViewportMinHeight,
  selectViewportWidth,
  setFirstLoadComplete,
  unsetUpdatingComponent,
} from '@/features/ui/uiSlice';
import useSyncIframeHeightToContent from '@/hooks/useSyncIframeHeightToContent';
import IframeSwapper from '@/features/layout/preview/IframeSwapper';
import ViewportOverlay from '@/features/layout/previewOverlay/ViewportOverlay';
import { useComponentHtmlMap } from '@/hooks/useComponentHtmlMap';
import { RegionSpotlight } from '@/features/layout/preview/RegionSpotlight/RegionSpotlight';

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
  const canvasMode = useAppSelector(selectCanvasMode);
  const viewportWidth = useAppSelector(selectViewportWidth);
  const viewportMinHeight = useAppSelector(selectViewportMinHeight);
  useComponentHtmlMap(iframeRef.current);

  useSyncIframeHeightToContent(
    iframeRef.current,
    previewContainerRef.current,
    viewportMinHeight,
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

    iframe.dataset.testXbContentInitialized = 'true';
    dispatch(setFirstLoadComplete(true));
  }, [dispatch, isReloading]);

  const containerStyles = {
    width: `${viewportWidth}px`,
    minHeight: `${viewportMinHeight}px`,
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
        interactive={canvasMode === CanvasMode.INTERACTIVE}
      />
      {canvasMode === CanvasMode.EDIT && (
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
