import type React from 'react';
import { useEffect, useRef, useState } from 'react';
import { useAppSelector } from '@/app/hooks';
import { selectLayoutForRegion } from '@/features/layout/layoutModelSlice';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import styles from './PreviewOverlay.module.css';
import useSyncElementSize from '@/hooks/useSyncElementSize';
import {
  selectCanvasViewPortScale,
  selectTargetSlot,
} from '@/features/ui/uiSlice';
import NameTag from '@/features/layout/preview/NameTag';
import clsx from 'clsx';

interface RegionOverlayProps {
  iframeRef: React.RefObject<HTMLIFrameElement>;
  regionId: string;
  regionName: string;
}

const RegionOverlay: React.FC<RegionOverlayProps> = ({
  regionId,
  iframeRef,
  regionName,
}) => {
  const layout = useAppSelector((state) =>
    selectLayoutForRegion(state, regionId),
  );
  const rootCanvasOverlayRef = useRef(null);
  const elementRect = useSyncElementSize(iframeRef.current, regionId);
  const canvasViewPortScale = useAppSelector(selectCanvasViewPortScale);
  const [overlayStyles, setOverlayStyles] = useState({});
  const targetSlot = useAppSelector(selectTargetSlot);

  useEffect(() => {
    const iframeDocument = iframeRef.current?.contentDocument;
    if (!iframeDocument) {
      return;
    }

    const elementInsideIframe = iframeDocument.querySelector(
      `[data-xb-region="${regionId}"]`,
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
  }, [elementRect, canvasViewPortScale, iframeRef, regionId]);

  return (
    <div
      ref={rootCanvasOverlayRef}
      className={clsx(
        styles.rootCanvasOverlay,
        {
          [styles.dropTarget]: regionId === targetSlot,
        },
        `xb--region-overlay__${regionId}`,
      )}
      style={overlayStyles}
    >
      {layout.components.map((component) => (
        <ComponentOverlay
          key={component.uuid}
          iframeRef={iframeRef}
          component={component}
          parentRegion={layout}
        />
      ))}
      {targetSlot === regionId && (
        <div className={clsx(styles.xbNameTag, styles.xbNameTagSlot)}>
          <NameTag
            name={`${regionName} region`}
            componentUuid={regionId}
            selected={true}
            nodeType={'root'}
          />
        </div>
      )}
    </div>
  );
};

export default RegionOverlay;
