import { useMemo } from 'react';
import clsx from 'clsx';
import { LockClosedIcon } from '@radix-ui/react-icons';
import { ContextMenu } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import UnifiedMenu from '@/components/UnifiedMenu';
import {
  filterNonMarkerComponents,
  isLockedSlotRegion,
} from '@/features/layout/exposedSlots';
import {
  overrideSlotDefaultContent,
  revertSlotOverride,
  selectExposedSlots,
  selectIsPerContentMode,
  selectSlotDefaults,
  selectSlotOverrides,
} from '@/features/layout/layoutModelSlice';
import { RegionNameTag } from '@/features/layout/preview/NameTag';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import EmptyRegionDropZone from '@/features/layout/previewOverlay/EmptyRegionDropZone';
import RegionDropZone from '@/features/layout/previewOverlay/RegionDropZone';
import {
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

  // Per-content editing: an exposed slot presents as a top-level slot region,
  // keyed by its backing field name. It is anchored by the slot marker the
  // template chrome's Twig emits (the chrome itself is inert, unmarked HTML),
  // and it is a single locked unit while the entity has not overridden a
  // non-empty template default.
  const perContentMode = useAppSelector(selectIsPerContentMode);
  const exposedSlots = useAppSelector(selectExposedSlots);
  const slotOverrides = useAppSelector(selectSlotOverrides);
  const slotDefaults = useAppSelector(selectSlotDefaults);
  const slotDefinition = perContentMode ? exposedSlots?.[region.id] : undefined;
  const isSlotRegion = !!slotDefinition;
  const slotSelectionId = slotDefinition
    ? `${slotDefinition.componentUuid}/${slotDefinition.slotName}`
    : undefined;
  const isLocked =
    isSlotRegion &&
    isLockedSlotRegion(region.id, exposedSlots, slotOverrides, slotDefaults);
  const isOverridden = !!(
    isSlotRegion && slotOverrides?.[region.id]?.overridden
  );
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const { setNonRoutedSelection } = useComponentSelection();
  const isSlotRegionSelected =
    !!slotSelectionId && selectedComponent === slotSelectionId;

  // A slot region is anchored on the slot the template chrome emits, not on a
  // region boundary: the chrome renders as inert HTML with no region marker.
  const regionGeometry =
    isSlotRegion && slotSelectionId
      ? geometryMap.slot[slotSelectionId]
      : geometryMap.region[region.id];

  // Marker nodes render nothing; hide them so an empty override shows empty.
  const visibleComponents = isSlotRegion
    ? filterNonMarkerComponents(region.components)
    : region.components;

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
        // Slot regions reuse the slot overlay's visual states: the locked
        // cursor, and the selected outline + fill when the locked unit is
        // selected.
        isSlotRegion && styles.slotOverlay,
        {
          [styles.dropTarget]: region.id === targetSlot,
          [styles.hovered]: isHovered,
          [styles.locked]: isSlotRegion && isLocked,
          [styles.selected]: isSlotRegion && isSlotRegionSelected,
        },
        `canvas--region-overlay__${region.id}`,
      )}
      style={overlayStyles}
      onMouseOver={handleItemMouseOver}
      onMouseOut={handleItemMouseOut}
    >
      {/* Per-content editing: a locked slot region is one selectable unit.
          Left-click selects it (its contextual panel offers Unlock and the
          template jump); right-click offers Unlock directly. */}
      {isSlotRegion && isLocked && slotSelectionId && (
        <>
          <ContextMenu.Root>
            <ContextMenu.Trigger>
              <button
                type="button"
                aria-label={`Locked slot ${region.name}`}
                className={styles.lockedSlotTrigger}
                data-testid={`slot-locked-${region.id}`}
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
              <UnifiedMenu.Label>{region.name}</UnifiedMenu.Label>
              <UnifiedMenu.Separator />
              <UnifiedMenu.Item
                onClick={() => dispatch(overrideSlotDefaultContent(region.id))}
                data-testid={`slot-unlock-${region.id}`}
              >
                Unlock
              </UnifiedMenu.Item>
            </UnifiedMenu.Content>
          </ContextMenu.Root>
          {(isHovered || isSlotRegionSelected) && (
            <div className={styles.lockBadge} aria-hidden="true">
              <LockClosedIcon />
              <span>{region.name}</span>
            </div>
          )}
        </>
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
            <UnifiedMenu.Label>{region.name}</UnifiedMenu.Label>
            <UnifiedMenu.Separator />
            <UnifiedMenu.Item
              onClick={() => dispatch(revertSlotOverride(region.id))}
              data-testid={`slot-revert-${region.id}`}
            >
              Revert to default
            </UnifiedMenu.Item>
          </UnifiedMenu.Content>
        </ContextMenu.Root>
      )}

      <div className={clsx(styles.canvasNameTag)}>
        <RegionNameTag
          name={region.name}
          id={region.id}
          nodeType={isSlotRegion ? 'region' : 'page'}
        />
      </div>

      {!isLocked && (
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
