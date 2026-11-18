import { useMemo } from 'react';
import clsx from 'clsx';
import TemplateIcon from '@assets/icons/template.svg?react';
import { ContextMenu } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import UnifiedMenu from '@/components/UnifiedMenu';
import {
  exposedSlotGeometryId,
  filterNonMarkerComponents,
  isInheritedSlotRegion,
} from '@/features/layout/exposedSlots';
import {
  selectExposedSlots,
  selectIsPerContentMode,
  selectSlotDefaults,
  selectSlotOverrides,
} from '@/features/layout/layoutModelSlice';
import { RegionNameTag } from '@/features/layout/preview/NameTag';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import SlotOverrideMenuItems from '@/features/layout/preview/SlotOverrideMenuItems';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import EmptyRegionDropZone from '@/features/layout/previewOverlay/EmptyRegionDropZone';
import RegionDropZone from '@/features/layout/previewOverlay/RegionDropZone';
import {
  selectDragging,
  selectEditorViewPortScale,
  selectIsComponentHovered,
  selectSelectedComponentUuid,
  selectTargetSlot,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';

import type React from 'react';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

import styles from './PreviewOverlay.module.css';

interface RegionOverlayProps {
  region: RegionNode;
}

const RegionOverlay: React.FC<RegionOverlayProps> = ({ region }) => {
  const { geometryMap } = usePreviewGeometry();
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const targetSlot = useAppSelector(selectTargetSlot);
  const dispatch = useAppDispatch();
  const isHovered = useAppSelector((state) =>
    selectIsComponentHovered(state, region.id),
  );

  // Per-content editing: an exposed slot is a top-level region, anchored by the
  // template's slot marker.
  const perContentMode = useAppSelector(selectIsPerContentMode);
  const exposedSlots = useAppSelector(selectExposedSlots);
  const slotOverrides = useAppSelector(selectSlotOverrides);
  const slotDefaults = useAppSelector(selectSlotDefaults);
  const slotDefinition = perContentMode ? exposedSlots?.[region.id] : undefined;
  const isSlotRegion = !!slotDefinition;
  const slotSelectionId = slotDefinition
    ? exposedSlotGeometryId(slotDefinition)
    : undefined;
  const isInherited =
    isSlotRegion &&
    isInheritedSlotRegion(region.id, exposedSlots, slotOverrides, slotDefaults);
  const isOverridden = !!(
    isSlotRegion && slotOverrides?.[region.id]?.overridden
  );
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  // Dragging a new or existing component in the preview.
  const { listDragging, previewDragging } = useAppSelector(selectDragging);
  const isDraggingComponent = listDragging || previewDragging;
  const { setNonRoutedSelection } = useComponentSelection();
  const isSlotRegionSelected =
    !!slotSelectionId && selectedComponent === slotSelectionId;
  // Marker nodes render nothing; hide them so an empty override shows empty.
  const visibleComponents = isSlotRegion
    ? filterNonMarkerComponents(region.components)
    : region.components;

  const regionGeometry = slotSelectionId
    ? geometryMap.slot[slotSelectionId]
    : geometryMap.region[region.id];
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
        // Slot regions reuse the slot overlay's visual states: the read-only
        // cursor, and the selected outline + fill when the inherited unit is
        // selected.
        isSlotRegion && styles.slotOverlay,
        {
          [styles.dropTarget]: region.id === targetSlot,
          [styles.hovered]: isHovered,
          [styles.inherited]: isSlotRegion && isInherited,
          [styles.selected]: isSlotRegion && isSlotRegionSelected,
        },
        `canvas--region-overlay__${region.id}`,
      )}
      style={overlayStyles}
      data-canvas-drop-container={region.id}
      onMouseOver={handleItemMouseOver}
      onMouseOut={handleItemMouseOut}
    >
      {/* Per-content editing: an inherited slot region is one selectable unit.
          Left-click selects it (its contextual panel offers Override);
          right-click offers Override directly. */}
      {isSlotRegion && isInherited && slotSelectionId && (
        <ContextMenu.Root>
          <ContextMenu.Trigger>
            <button
              type="button"
              aria-label={`Slot ${region.name} (template default)`}
              className={styles.inheritedSlotTrigger}
              data-testid={`slot-inherited-${region.id}`}
              onClick={(event) => {
                event.stopPropagation();
                setNonRoutedSelection(slotSelectionId);
              }}
            />
          </ContextMenu.Trigger>
          <UnifiedMenu.Content
            menuType="context"
            align="start"
            side="right"
            aria-label={`Options for ${region.name}`}
          >
            <SlotOverrideMenuItems
              region={region}
              isInherited
              testIdPrefix="slot"
            />
          </UnifiedMenu.Content>
        </ContextMenu.Root>
      )}

      {/* Per-content editing: an inherited slot has no drop zones. While a
          component is dragged, say why it cannot be dropped here. */}
      {isSlotRegion && isInherited && isDraggingComponent && (
        <div
          className={styles.inheritedSlotDragHint}
          data-testid={`slot-inherited-drag-hint-${region.id}`}
        >
          <TemplateIcon />
          <div>Override this slot to add content</div>
        </div>
      )}

      {/* Per-content editing: reverting an override is a rare action, so it
          lives in the slot region's right-click menu. */}
      {isSlotRegion && isOverridden && (
        <ContextMenu.Root>
          <ContextMenu.Trigger>
            <div
              aria-label={`Slot ${region.name}`}
              className={styles.regionItem}
              data-canvas-overlay="true"
            />
          </ContextMenu.Trigger>
          <UnifiedMenu.Content
            menuType="context"
            align="start"
            side="right"
            aria-label={`Options for ${region.name}`}
          >
            <SlotOverrideMenuItems
              region={region}
              isInherited={false}
              testIdPrefix="slot"
            />
          </UnifiedMenu.Content>
        </ContextMenu.Root>
      )}

      <div className={clsx(styles.canvasNameTag)}>
        <RegionNameTag
          name={region.name}
          id={region.id}
          nodeType={isSlotRegion ? 'slot' : 'page'}
        />
      </div>

      {!isInherited && (
        <>
          {visibleComponents.map((component, index) => (
            <ComponentOverlay
              key={component.uuid}
              component={component}
              parentRegion={region}
              index={index}
            />
          ))}

          {!visibleComponents.length && <EmptyRegionDropZone region={region} />}
          {!!visibleComponents.length && (
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
