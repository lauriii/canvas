import ReactDOM from 'react-dom';
import type React from 'react';
import { useCallback } from 'react';
import { useEffect, useRef, useState } from 'react';
import styles from './PreviewOverlay.module.css';
import { useAppSelector } from '@/app/hooks';
import useWindowResizeListener from '@/hooks/useWindowResizeListener';
import useResizeObserver from '@/hooks/useResizeObserver';

import {
  DEFAULT_REGION,
  selectCanvasViewPortScale,
  selectDragging,
  selectZooming,
} from '@/features/ui/uiSlice';
import RegionOverlay from '@/features/layout/previewOverlay/RegionOverlay';
import clsx from 'clsx';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { useNavigate, useParams } from 'react-router-dom';
import useTransitionEndListener from '@/hooks/useTransitionEndListener';

interface ViewportOverlayProps {
  iframeRef: React.RefObject<HTMLIFrameElement>;
  previewContainerRef: React.RefObject<HTMLDivElement>;
}
interface Rect {
  left: Number;
  top: Number;
  width: Number;
  height: Number;
}
const ViewportOverlay: React.FC<ViewportOverlayProps> = (props) => {
  const { iframeRef, previewContainerRef } = props;
  const [portalRoot, setPortalRoot] = useState<HTMLElement | null>(null);
  const positionDivRef = useRef(null);
  const canvasViewPortScale = useAppSelector(selectCanvasViewPortScale);
  const layout = useAppSelector(selectLayout);
  const [rect, setRect] = useState<Rect | null>(null);
  const { treeDragging } = useAppSelector(selectDragging);
  const isZooming = useAppSelector(selectZooming);
  const navigate = useNavigate();
  const { regionId: focusedRegion = DEFAULT_REGION } = useParams();

  let displayedRegions = layout.filter((region) => {
    return region.components.length > 0 || region.id === DEFAULT_REGION;
  });

  const updateRect = useCallback(() => {
    // The top and left must equal the distance from the parent (positionAnchor, which is always static) to the iFrame.
    // Using getBoundingClientRect takes the scale/transform origin into account whereas .offSet doesn't.
    if (previewContainerRef.current) {
      const parent = document.getElementById('positionAnchor');
      if (!parent) {
        return;
      }
      const parentRect = parent.getBoundingClientRect();
      const iframeRect = previewContainerRef.current.getBoundingClientRect();

      const newRect = {
        left: iframeRect.left - parentRect.left,
        top: iframeRect.top - parentRect.top,
        width: iframeRect.width,
        height: iframeRect.height,
      };

      setRect((prevState) => {
        if (
          !prevState ||
          prevState.left !== newRect.left ||
          prevState.top !== newRect.top ||
          prevState.width !== newRect.width ||
          prevState.height !== newRect.height
        ) {
          return newRect;
        }
        return prevState;
      });
    }
  }, [previewContainerRef]);

  useWindowResizeListener(updateRect);
  useResizeObserver(previewContainerRef, updateRect);

  useTransitionEndListener(
    previewContainerRef.current
      ? previewContainerRef.current.closest('.xbCanvasScalingContainer')
      : null,
    updateRect,
  );

  useEffect(() => {
    const targetDiv = document.getElementById('xbPreviewOverlay');
    if (targetDiv) {
      setPortalRoot(targetDiv);
    }
    updateRect();
  }, [previewContainerRef, updateRect, canvasViewPortScale]);

  // When double-clicking "outside" the focused region, set the focus back to the default region (by navigating to /editor).
  function handleDoubleClick(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    if (focusedRegion !== DEFAULT_REGION) {
      navigate('/editor');
    }
  }

  if (!portalRoot || !rect || treeDragging) return null;

  // This overlay is portalled and rendered higher up the DOM tree to ensure that when the canvas is zoomed, the UI
  // rendered inside the overlay does not also scale. We don't want tiny text in the UI when a user zooms out for instance.
  return ReactDOM.createPortal(
    <div
      ref={positionDivRef}
      className={clsx('xb--viewport-overlay', styles.viewportOverlay, {
        [styles.isZooming]: isZooming,
      })}
      onDoubleClick={handleDoubleClick}
      style={{
        top: `${rect.top}px`,
        left: `${rect.left}px`,
        width: `${rect.width}px`,
        height: `${rect.height}px`,
      }}
    >
      {displayedRegions.map((region) => (
        <RegionOverlay
          iframeRef={iframeRef}
          region={region}
          regionId={region.id}
          key={region.id}
          regionName={region.name}
        />
      ))}
    </div>,
    portalRoot,
  );
};

export default ViewportOverlay;
