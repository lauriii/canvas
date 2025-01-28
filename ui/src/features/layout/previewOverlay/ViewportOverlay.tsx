import ReactDOM from 'react-dom';
import type React from 'react';
import { useCallback } from 'react';
import { useEffect, useRef, useState } from 'react';
import styles from './PreviewOverlay.module.css';
import { useAppSelector } from '@/app/hooks';
import useWindowResizeListener from '@/hooks/useWindowResizeListener';
import useResizeObserver from '@/hooks/useResizeObserver';

import {
  selectCanvasViewPortScale,
  selectDragging,
  selectPanning,
} from '@/features/ui/uiSlice';
import RegionOverlay from '@/features/layout/previewOverlay/RegionOverlay';
import clsx from 'clsx';
import type { ViewPortSize } from '@/features/layout/preview/Viewport';
import { selectLayout } from '@/features/layout/layoutModelSlice';

interface ViewportOverlayProps {
  iframeRef: React.RefObject<HTMLIFrameElement>;
  size: ViewPortSize;
}
interface Rect {
  left: Number;
  top: Number;
  width: Number;
  height: Number;
}
const ViewportOverlay: React.FC<ViewportOverlayProps> = (props) => {
  const { iframeRef, size } = props;
  const [portalRoot, setPortalRoot] = useState<HTMLElement | null>(null);
  const positionDivRef = useRef(null);
  const canvasViewPortScale = useAppSelector(selectCanvasViewPortScale);
  const { isDragging } = useAppSelector(selectDragging);
  const layout = useAppSelector(selectLayout);
  const [rect, setRect] = useState<Rect | null>(null);
  const { isPanning } = useAppSelector(selectPanning);

  const updateRect = useCallback(() => {
    // The top and left must equal the distance from the parent (positionAnchor, which is always static) to the iFrame.
    // Using getBoundingClientRect takes the scale/transform origin into account whereas .offSet doesn't.
    if (iframeRef.current) {
      const parent = document.getElementById('positionAnchor');
      if (!parent) {
        return;
      }
      const parentRect = parent.getBoundingClientRect();
      const iframeRect = iframeRef.current.getBoundingClientRect();

      const newRect = {
        left: iframeRect.left - parentRect.left,
        top: iframeRect.top - parentRect.top,
        width: iframeRect.width,
        height: iframeRect.height,
      };

      setRect(newRect);
    }
  }, [iframeRef]);

  useWindowResizeListener(updateRect);
  useResizeObserver(iframeRef, updateRect);

  useEffect(() => {
    const targetDiv = document.getElementById('xbPreviewOverlay');
    if (targetDiv) {
      setPortalRoot(targetDiv);
    }
    updateRect();
  }, [iframeRef, updateRect, canvasViewPortScale]);

  if (!portalRoot || !rect) return null;

  // This overlay is portalled and rendered higher up the DOM tree to ensure that when the canvas is zoomed, the UI
  // rendered inside the overlay does not also scale. We don't want tiny text in the UI when a user zooms out for instance.
  return ReactDOM.createPortal(
    <div
      ref={positionDivRef}
      className={clsx('xb--viewport-overlay', styles.viewportOverlay, {
        [styles.isPanning]: isPanning,
      })}
      data-xb-viewport-size={size}
      style={{
        top: `${rect.top}px`,
        left: `${rect.left}px`,
        width: `${rect.width}px`,
        height: `${rect.height}px`,
        pointerEvents: isDragging ? 'none' : 'all',
      }}
    >
      {layout.map((region) => (
        <RegionOverlay
          iframeRef={iframeRef}
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
