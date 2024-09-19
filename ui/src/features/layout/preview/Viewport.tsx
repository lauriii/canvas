import styles from './Preview.module.css';
import type React from 'react';
import { useRef, useEffect, useState } from 'react';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { Progress } from '@radix-ui/themes';
import {
  selectDragging,
  selectPanning,
  selectHoveredComponent,
  selectSelectedComponent,
  setFirstLoadComplete,
} from '@/features/ui/uiSlice';
import Outline from '@/features/layout/preview/Outline';
import useIframeKeyHandlers from '@/hooks/useIframeKeyHandlers';
import useSyncIframeHeightToContent from '@/hooks/useSyncIframeHeightToContent';
import ViewportToolbar from '@/features/layout/preview/ViewportToolbar';
import RightClickMenu from '@/features/layout/preview/RightClickMenu';
import IframeSwapper from '@/features/layout/preview/IframeSwapper';
import usePreviewSortable from '@/hooks/usePreviewSortable';
import usePreviewComponentInteractions from '@/hooks/usePreviewComponentInteractions';

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
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const hoveredComponent = useAppSelector(selectHoveredComponent);

  const { isDragging } = useAppSelector(selectDragging);
  const { isPanning } = useAppSelector(selectPanning);
  const dispatch = useAppDispatch();

  useIframeKeyHandlers(iframeRef.current);
  usePreviewSortable(iframeRef.current);
  useSyncIframeHeightToContent(
    iframeRef.current,
    previewContainerRef.current,
    height,
    width,
  );
  const mouseEventPosition = usePreviewComponentInteractions(
    iframeRef.current,
    size,
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
    dispatch(setFirstLoadComplete());
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
        />
        {hoveredComponent && (
          <RightClickMenu
            iframeRef={iframeRef}
            elementId={hoveredComponent}
            viewportSize={size}
            mouseEventPosition={mouseEventPosition}
          />
        )}
        {!isDragging && !isPanning && (
          <>
            <Outline
              elementId={selectedComponent}
              iframeRef={iframeRef}
              selected={true}
            />
            {selectedComponent !== hoveredComponent && (
              <Outline
                elementId={hoveredComponent}
                iframeRef={iframeRef}
                selected={false}
              />
            )}
          </>
        )}
      </div>
    </div>
  );
};

export default Viewport;
