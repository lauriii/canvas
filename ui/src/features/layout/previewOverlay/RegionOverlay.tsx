import { useEffect, useState } from 'react';
import clsx from 'clsx';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useDataToHtmlMapValue } from '@/features/layout/preview/DataToHtmlMapContext';
import { RegionNameTag } from '@/features/layout/preview/NameTag';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import EmptyRegionDropZone from '@/features/layout/previewOverlay/EmptyRegionDropZone';
import RegionDropZone from '@/features/layout/previewOverlay/RegionDropZone';
import {
  selectEditorViewPortScale,
  selectIsComponentHovered,
  selectTargetSlot,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useSyncPreviewElementSize from '@/hooks/useSyncPreviewElementSize';

import type React from 'react';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

import styles from './PreviewOverlay.module.css';

interface RegionOverlayProps {
  iframeRef: React.RefObject<HTMLIFrameElement>;
  region: RegionNode;
}

const RegionOverlay: React.FC<RegionOverlayProps> = ({ iframeRef, region }) => {
  const { regionsMap } = useDataToHtmlMapValue();
  const { elementRect } = useSyncPreviewElementSize(
    regionsMap[region.id]?.elements,
  );
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const [overlayStyles, setOverlayStyles] = useState({});
  const targetSlot = useAppSelector(selectTargetSlot);
  const dispatch = useAppDispatch();
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, region.id);
  });

  useEffect(() => {
    setOverlayStyles({
      top: `${elementRect.top * editorViewPortScale}px`,
      left: `${elementRect.left * editorViewPortScale}px`,
      width: `${elementRect.width * editorViewPortScale}px`,
      height: `${elementRect.height * editorViewPortScale}px`,
    });
  }, [elementRect, editorViewPortScale, region.id, regionsMap]);

  function handleItemMouseOver(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(setHoveredComponent(region.id));
  }

  function handleItemMouseOut(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(unsetHoveredComponent());
  }

  return (
    <div
      className={clsx(
        styles.pageOverlay,
        {
          [styles.dropTarget]: region.id === targetSlot,
          [styles.hovered]: isHovered,
        },
        `canvas--region-overlay__${region.id}`,
      )}
      style={overlayStyles}
      onMouseOver={handleItemMouseOver}
      onMouseOut={handleItemMouseOut}
    >
      <div className={clsx(styles.canvasNameTag)}>
        <RegionNameTag name={region.name} id={region.id} nodeType="page" />
      </div>

      {region.components.map((component, index) => (
        <ComponentOverlay
          key={component.uuid}
          iframeRef={iframeRef}
          component={component}
          index={index}
        />
      ))}

      {!region.components.length && <EmptyRegionDropZone region={region} />}
      {!!region.components.length && (
        <>
          <RegionDropZone region={region} position="before" />
          <RegionDropZone region={region} position="after" />
        </>
      )}
    </div>
  );
};

export default RegionOverlay;
