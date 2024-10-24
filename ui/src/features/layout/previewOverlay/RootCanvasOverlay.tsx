import type React from 'react';
import { useEffect, useRef, useState } from 'react';
import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import styles from './PreviewOverlay.module.css';
import useSyncElementSize from '@/hooks/useSyncElementSize';
import { selectCanvasViewPortScale } from '@/features/ui/uiSlice';

interface RootCanvasOverlayProps {
  iframeRef: React.RefObject<HTMLIFrameElement>;
}

const RootCanvasOverlay: React.FC<RootCanvasOverlayProps> = (props) => {
  const { iframeRef } = props;
  const layout = useAppSelector(selectLayout);
  const rootCanvasOverlayRef = useRef(null);
  const elementRect = useSyncElementSize(iframeRef.current, 'root');
  const canvasViewPortScale = useAppSelector(selectCanvasViewPortScale);
  const [overlayStyles, setOverlayStyles] = useState({});

  useEffect(() => {
    const iframeDocument = iframeRef.current?.contentDocument;
    if (!iframeDocument) {
      return;
    }

    const elementInsideIframe = iframeDocument.querySelector(
      `[data-xb-uuid="root"]`,
    );
    if (!elementInsideIframe) {
      return;
    }
    const computedStyle = window.getComputedStyle(elementInsideIframe);
    setOverlayStyles({
      top: `${elementRect.top * canvasViewPortScale}px`,
      left: `${elementRect.left * canvasViewPortScale}px`,
      width: `${elementRect.width * canvasViewPortScale}px`,
      height: `${elementRect.height * canvasViewPortScale}px`,
      paddingTop: `${parseFloat(computedStyle.paddingTop) * canvasViewPortScale}px`,
      paddingBottom: `${parseFloat(computedStyle.paddingBottom) * canvasViewPortScale}px`,
    });
  }, [elementRect, canvasViewPortScale, iframeRef]);

  return (
    <div
      ref={rootCanvasOverlayRef}
      className={styles.rootCanvasOverlay}
      style={overlayStyles}
    >
      {layout.children.map((component) => (
        <ComponentOverlay
          key={component.uuid}
          iframeRef={iframeRef}
          component={component}
          parentComponent={{ uuid: 'root' }}
        />
      ))}
    </div>
  );
};

export default RootCanvasOverlay;
