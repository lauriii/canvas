import styles from './Preview.module.css';
import type React from 'react';
import { useRef, useEffect, useState } from 'react';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { Progress } from '@radix-ui/themes';
import {
  CanvasMode,
  selectCanvasMode,
  setFirstLoadComplete,
} from '@/features/ui/uiSlice';
import useSyncIframeHeightToContent from '@/hooks/useSyncIframeHeightToContent';
import ViewportToolbar from '@/features/layout/preview/ViewportToolbar';
import IframeSwapper from '@/features/layout/preview/IframeSwapper';
import useRenderPreviewEmptySlotPlaceholders from '@/hooks/useRenderPreviewEmptySlotPlaceholders';
import ViewportOverlay from '@/features/layout/previewOverlay/ViewportOverlay';
import { useComponentHtmlMap } from '@/hooks/useComponentHtmlMap';
import { useDataToHtmlMapValue } from '@/features/layout/preview/DataToHtmlMapContext';
import { RegionSpotlight } from '@/features/layout/preview/RegionSpotlight/RegionSpotlight';
import useRenderPreviewEmptyRegionPlaceholder from '@/hooks/useRenderPreviewEmptyRegionPlaceholder';

export type ViewPortSize = 'lg' | 'sm';
export interface ViewportProps {
  size: ViewPortSize;
  name: string;
  height: number;
  width: number;
  isFetching: boolean;
  frameSrcDoc: string; // HTML as a string to be rendered in the iFrame
}

const Viewport: React.FC<ViewportProps> = (props) => {
  const { name, height, width, frameSrcDoc, isFetching, size } = props;
  const [isReloading, setIsReloading] = useState(true);
  const [showProgressIndicator, setShowProgressIndicator] = useState(false);
  const progressTimerRef = useRef<number | null>();
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const previewContainerRef = useRef<HTMLDivElement>(null);
  const dispatch = useAppDispatch();
  const canvasMode = useAppSelector(selectCanvasMode);
  useComponentHtmlMap(iframeRef.current);

  const { slotsMap, regionsMap } = useDataToHtmlMapValue();

  useRenderPreviewEmptySlotPlaceholders(iframeRef.current, slotsMap);
  useRenderPreviewEmptyRegionPlaceholder(iframeRef.current, regionsMap);

  useSyncIframeHeightToContent(
    iframeRef.current,
    previewContainerRef.current,
    height,
    width,
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
    }
    return () => {
      if (progressTimerRef.current) {
        clearTimeout(progressTimerRef.current);
      }
    };
  }, [isFetching, isReloading]);

  useEffect(() => {
    const iframe = iframeRef.current;
    if (!iframe?.srcdoc || isReloading) {
      return;
    }

    iframe.dataset.testXbContentInitialized = 'true';
    dispatch(setFirstLoadComplete(true));
  }, [dispatch, isReloading]);

  return (
    <div>
      <ViewportToolbar size={size} name={name} width={width} height={height} />
      <div className={styles.previewContainer} ref={previewContainerRef}>
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
          size={size}
          setIsReloading={setIsReloading}
          interactive={canvasMode === CanvasMode.INTERACTIVE}
        />
        {canvasMode === CanvasMode.EDIT && (
          <>
            <ViewportOverlay
              iframeRef={iframeRef}
              previewContainerRef={previewContainerRef}
              size={size}
            />
            <RegionSpotlight />
          </>
        )}
      </div>
    </div>
  );
};

export default Viewport;
