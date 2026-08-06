import { useMemo } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectLayoutForRegion } from '@/features/layout/layoutModelSlice';
import { RegionNameTag } from '@/features/layout/preview/NameTag';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import RegionContextMenu from '@/features/layout/preview/RegionContextMenu';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import EmptyRegionDropZone from '@/features/layout/previewOverlay/EmptyRegionDropZone';
import RegionDropZone from '@/features/layout/previewOverlay/RegionDropZone';
import {
  DEFAULT_REGION,
  selectDragging,
  selectEditorViewPortScale,
  selectIsComponentHovered,
  selectTargetSlot,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';

import type React from 'react';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

import styles from './PreviewOverlay.module.css';

interface RegionOverlayProps {
  region: RegionNode;
}

const RegionOverlay: React.FC<RegionOverlayProps> = ({ region }) => {
  const layout = useAppSelector((state) =>
    selectLayoutForRegion(state, region.id),
  );
  const { geometryMap } = usePreviewGeometry();
  const { regionId: focusedRegion = DEFAULT_REGION } = useParams();
  const regionGeometry = geometryMap.region[region.id];
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const targetSlot = useAppSelector(selectTargetSlot);
  const disableRegion = focusedRegion !== region.id;
  const dispatch = useAppDispatch();
  const { isDragging } = useAppSelector(selectDragging);
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, region.id);
  });
  const { setSelectedRegion } = useEditorNavigation();
  const showHovered = isHovered && focusedRegion === DEFAULT_REGION;
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
    if (!isDragging) {
      dispatch(setHoveredComponent(region.id));
    }
  }

  function handleItemMouseOut(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(unsetHoveredComponent());
  }

  function handleRegionDblClick(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    if (focusedRegion !== region.id) {
      setSelectedRegion(region.id);
    } else {
      setSelectedRegion();
    }
  }

  if (
    !regionGeometry ||
    (focusedRegion !== DEFAULT_REGION && focusedRegion !== region.id)
  ) {
    return null;
  }

  const isPage = region.id === DEFAULT_REGION;

  return (
    <div
      className={clsx(
        [isPage && styles.pageOverlay, !isPage && styles.regionOverlay],
        {
          [styles.dropTarget]: region.id === targetSlot,
          [styles.hovered]: showHovered,
        },
        `canvas--region-overlay__${region.id}`,
      )}
      style={overlayStyles}
      onMouseOver={handleItemMouseOver}
      onMouseOut={handleItemMouseOut}
      onDoubleClick={handleRegionDblClick}
    >
      {!isPage && (
        <RegionContextMenu region={region}>
          <div
            aria-label={`Global region ${region.name}`}
            className={styles.regionItem}
            data-canvas-overlay="true"
          />
        </RegionContextMenu>
      )}

      <div className={clsx(styles.canvasNameTag)}>
        <RegionNameTag
          name={region.name}
          id={region.id}
          nodeType={isPage ? 'page' : 'region'}
        />
      </div>

      {!disableRegion && (
        <>
          {layout.components.map((component, index) => (
            <ComponentOverlay
              key={component.uuid}
              component={component}
              parentRegion={layout}
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
        </>
      )}
    </div>
  );
};

export default RegionOverlay;
