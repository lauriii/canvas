import type React from 'react';
import { useEffect, useRef, useState } from 'react';
import { useAppSelector } from '@/app/hooks';
import type { RegionNode } from '@/features/layout/layoutModelSlice';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import styles from './PreviewOverlay.module.css';
import useSyncElementSize from '@/hooks/useSyncElementSize';
import {
  selectCanvasViewPortScale,
  selectTargetSlot,
} from '@/features/ui/uiSlice';
import NameTag from '@/features/layout/preview/NameTag';
import clsx from 'clsx';

interface RootCanvasOverlayProps {
  iframeRef: React.RefObject<HTMLIFrameElement>;
}

const RootCanvasOverlay: React.FC<RootCanvasOverlayProps> = (props) => {
  const { iframeRef } = props;
  const layout = useAppSelector(selectLayout);
  const rootCanvasOverlayRef = useRef(null);
  const elementRect = useSyncElementSize(iframeRef.current, 'content');
  const canvasViewPortScale = useAppSelector(selectCanvasViewPortScale);
  const [overlayStyles, setOverlayStyles] = useState({});
  const targetSlot = useAppSelector(selectTargetSlot);

  useEffect(() => {
    const iframeDocument = iframeRef.current?.contentDocument;
    if (!iframeDocument) {
      return;
    }

    const elementInsideIframe = iframeDocument.querySelector(
      `[data-xb-uuid="content"]`,
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
      className={clsx(styles.rootCanvasOverlay, {
        [styles.dropTarget]: 'content' === targetSlot,
      })}
      style={overlayStyles}
    >
      {layout.map((node: RegionNode) =>
        node.components.map((component) => (
          <ComponentOverlay
            key={component.uuid}
            iframeRef={iframeRef}
            component={component}
            parentRegion={node}
          />
        )),
      )}
      {targetSlot === 'content' && (
        <div className={clsx(styles.xbNameTag, styles.xbNameTagSlot)}>
          <NameTag
            name={'Content region'}
            componentUuid={'content'}
            selected={true}
            nodeType={'root'}
          />
        </div>
      )}
    </div>
  );
};

export default RootCanvasOverlay;
