import { useMemo } from 'react';
import clsx from 'clsx';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { RegionNameTag } from '@/features/layout/preview/NameTag';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
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

import type React from 'react';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

import styles from './PreviewOverlay.module.css';

interface RegionOverlayProps {
  region: RegionNode;
}

const RegionOverlay: React.FC<RegionOverlayProps> = ({ region }) => {
  const { geometryMap } = usePreviewGeometry();
  const regionGeometry = geometryMap.region[region.id];
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const targetSlot = useAppSelector(selectTargetSlot);
  const dispatch = useAppDispatch();
  const isHovered = useAppSelector((state) =>
    selectIsComponentHovered(state, region.id),
  );
  const overlayStyles = useMemo(
    () => ({
      top: `${(regionGeometry?.rect.top ?? 0) * editorViewPortScale}px`,
      left: `${(regionGeometry?.rect.left ?? 0) * editorViewPortScale}px`,
      width: `${(regionGeometry?.rect.width ?? 0) * editorViewPortScale}px`,
      height: `${(regionGeometry?.rect.height ?? 0) * editorViewPortScale}px`,
    }),
    [editorViewPortScale, regionGeometry?.rect],
  );

  function handleItemMouseOver(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(setHoveredComponent(region.id));
  }

  function handleItemMouseOut(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(unsetHoveredComponent());
  }

  if (!regionGeometry) {
    return null;
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
          component={component}
          parentRegion={region}
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
