import type React from 'react';
import { useEffect, useRef, useState } from 'react';
import { useAppSelector } from '@/app/hooks';
import { selectLayoutForRegion } from '@/features/layout/layoutModelSlice';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import styles from './PreviewOverlay.module.css';
import {
  DEFAULT_REGION,
  selectCanvasViewPortScale,
  selectTargetSlot,
} from '@/features/ui/uiSlice';
import NameTag from '@/features/layout/preview/NameTag';
import clsx from 'clsx';
import { useDataToHtmlMapValue } from '@/features/layout/preview/DataToHtmlMapContext';
import useSyncPreviewElementSize from '@/hooks/useSyncPreviewElementSize';
import useXbParams from '@/hooks/useXbParams';

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
  const regionOverlayRef = useRef(null);
  const { regionsMap } = useDataToHtmlMapValue();
  const { regionId: focusedRegion = DEFAULT_REGION } = useXbParams();
  const elementRect = useSyncPreviewElementSize(regionsMap[regionId]?.element);
  const canvasViewPortScale = useAppSelector(selectCanvasViewPortScale);
  const [overlayStyles, setOverlayStyles] = useState({});
  const targetSlot = useAppSelector(selectTargetSlot);
  const disableRegion = focusedRegion !== regionId;

  useEffect(() => {
    const elementInsideIframe = regionsMap[regionId]?.element;
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
      pointerEvents: disableRegion ? 'none' : 'inherit',
    });
  }, [elementRect, canvasViewPortScale, regionId, disableRegion, regionsMap]);

  return (
    <div
      ref={regionOverlayRef}
      className={clsx(
        styles.regionOverlay,
        {
          [styles.dropTarget]: regionId === targetSlot,
        },
        `xb--region-overlay__${regionId}`,
      )}
      style={overlayStyles}
    >
      {!disableRegion && (
        <>
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
        </>
      )}
    </div>
  );
};

export default RegionOverlay;
