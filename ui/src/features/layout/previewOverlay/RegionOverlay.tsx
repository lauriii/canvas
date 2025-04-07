import type React from 'react';
import { useEffect, useState } from 'react';
import { useAppSelector } from '@/app/hooks';
import type { RegionNode } from '@/features/layout/layoutModelSlice';
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
import RegionDropZone from '@/features/layout/previewOverlay/RegionDropZone';
import EmptyRegionDropZone from '@/features/layout/previewOverlay/EmptyRegionDropZone';

interface RegionOverlayProps {
  iframeRef: React.RefObject<HTMLIFrameElement>;
  regionId: string;
  regionName: string;
  region: RegionNode;
  size: string;
}

const RegionOverlay: React.FC<RegionOverlayProps> = ({
  iframeRef,
  size,
  region,
}) => {
  const layout = useAppSelector((state) =>
    selectLayoutForRegion(state, region.id),
  );
  const { regionsMap } = useDataToHtmlMapValue();
  const { regionId: focusedRegion = DEFAULT_REGION } = useXbParams();
  const elementRect = useSyncPreviewElementSize(
    regionsMap[region.id]?.elements,
  );
  const canvasViewPortScale = useAppSelector(selectCanvasViewPortScale);
  const [overlayStyles, setOverlayStyles] = useState({});
  const targetSlot = useAppSelector(selectTargetSlot);
  const disableRegion = focusedRegion !== region.id;

  useEffect(() => {
    setOverlayStyles({
      top: `${elementRect.top * canvasViewPortScale}px`,
      left: `${elementRect.left * canvasViewPortScale}px`,
      width: `${elementRect.width * canvasViewPortScale}px`,
      height: `${elementRect.height * canvasViewPortScale}px`,
    });
  }, [elementRect, canvasViewPortScale, region.id, disableRegion, regionsMap]);

  return (
    <div
      className={clsx(
        styles.regionOverlay,
        {
          [styles.dropTarget]: region.id === targetSlot,
        },
        `xb--region-overlay__${region.id}`,
      )}
      style={overlayStyles}
    >
      {!disableRegion && (
        <>
          {layout.components.map((component, index) => (
            <ComponentOverlay
              key={component.uuid}
              iframeRef={iframeRef}
              component={component}
              parentRegion={layout}
              size={size}
              index={index}
            />
          ))}
          <div className={clsx(styles.xbNameTag, styles.xbNameTagSlot)}>
            <NameTag
              name={`${region.name} region`}
              id={region.id}
              nodeType={'root'}
            />
          </div>
          {!region.components.length && (
            <EmptyRegionDropZone region={region} size={size} />
          )}
          {!!region.components.length && (
            <>
              <RegionDropZone region={region} position="before" size={size} />
              <RegionDropZone region={region} position="after" size={size} />
            </>
          )}
        </>
      )}
    </div>
  );
};

export default RegionOverlay;
