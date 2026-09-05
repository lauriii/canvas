import { useMemo } from 'react';
import clsx from 'clsx';
import { BoxIcon, DotsHorizontalIcon } from '@radix-ui/react-icons';
import { DropdownMenu } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { findExposedSlotEntry } from '@/features/layout/exposedSlots';
import { selectExposedSlots } from '@/features/layout/layoutModelSlice';
import { SlotNameTag } from '@/features/layout/preview/NameTag';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import SlotContextMenu, {
  SlotContextMenuContent,
} from '@/features/layout/preview/SlotContextMenu';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import EmptySlotDropZone from '@/features/layout/previewOverlay/EmptySlotDropZone';
import {
  EditorFrameContext,
  selectEditorFrameContext,
  selectEditorViewPortScale,
  selectIsComponentHovered,
  selectSelectedComponentUuid,
  selectTargetSlot,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import useGetComponentName from '@/hooks/useGetComponentName';

import type React from 'react';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import styles from './PreviewOverlay.module.css';

// import SlotDropZone from '@/features/layout/previewOverlay/SlotDropZone';

export interface SlotOverlayProps {
  slot: SlotNode;
  parentComponent: ComponentNode;
  disableDrop: boolean;
}

const SlotOverlay: React.FC<SlotOverlayProps> = ({
  slot,
  parentComponent,
  disableDrop,
}) => {
  const { geometryMap } = usePreviewGeometry();
  const slotId = slot.id;
  const slotGeometry = geometryMap.slot[slotId];
  const parentGeometry = geometryMap.component[parentComponent.uuid];
  const offsetLeft =
    slotGeometry && parentGeometry
      ? slotGeometry.rect.left - parentGeometry.rect.left
      : 0;
  const offsetTop =
    slotGeometry && parentGeometry
      ? slotGeometry.rect.top - parentGeometry.rect.top
      : 0;
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const { setNonRoutedSelection } = useComponentSelection();
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, slotId);
  });
  const targetSlot = useAppSelector(selectTargetSlot);
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const slotName = useGetComponentName(slot, parentComponent);
  const parentComponentName = useGetComponentName(parentComponent);

  // Template editor: exposed-slot marking + management controls.
  const dispatch = useAppDispatch();
  const isTemplateContext =
    useAppSelector(selectEditorFrameContext) === EditorFrameContext.TEMPLATE;
  const exposedSlots = useAppSelector(selectExposedSlots);
  const exposed = isTemplateContext
    ? findExposedSlotEntry(exposedSlots, parentComponent.uuid, slot.name)
    : null;
  const isActiveExposed = !!exposed;
  const chipLabel = exposed ? exposed.definition.label : slotName;

  const style: React.CSSProperties = useMemo(
    () => ({
      height: (slotGeometry?.rect.height ?? 0) * editorViewPortScale,
      width: (slotGeometry?.rect.width ?? 0) * editorViewPortScale,
      top: offsetTop * editorViewPortScale,
      left: offsetLeft * editorViewPortScale,
      pointerEvents: 'none',
    }),
    [
      editorViewPortScale,
      offsetLeft,
      offsetTop,
      slotGeometry?.rect.height,
      slotGeometry?.rect.width,
    ],
  );

  if (!slotGeometry || !parentGeometry) {
    return null;
  }

  return (
    <div
      aria-label={`${slotName} (${parentComponentName})`}
      className={clsx('slotOverlay', styles.slotOverlay, {
        [styles.selected]: slotId === selectedComponent,
        [styles.hovered]: isHovered,
        [styles.dropTarget]: slotId === targetSlot,
        [styles.exposed]: isActiveExposed,
      })}
      data-canvas-type="slot"
      data-canvas-exposed={exposed ? true : undefined}
      style={style}
    >
      {/* Template editor: a full-cover trigger opens the slot menu on right-click
          (mirrors RegionOverlay) and selects the slot on left-click, so its
          contextual panel offers to expose it (or shows usage once exposed).
          Rendered first so drop zones and child component overlays remain
          interactive on top of it. The slot id contains a slash, so the
          selection is held in redux only (@see setNonRoutedSelection). */}
      {isTemplateContext && (
        <SlotContextMenu slot={slot} parentComponent={parentComponent}>
          <button
            type="button"
            aria-label={`Slot ${slotName} (${parentComponentName})`}
            className={styles.slotContextTrigger}
            data-canvas-overlay="true"
            onClick={(event) => {
              event.stopPropagation();
              setNonRoutedSelection(slotId);
            }}
            onMouseOver={(event) => {
              event.stopPropagation();
              dispatch(setHoveredComponent(slotId));
            }}
            onMouseOut={(event) => {
              event.stopPropagation();
              dispatch(unsetHoveredComponent());
            }}
          />
        </SlotContextMenu>
      )}
      {isTemplateContext
        ? // Template editor: exposed slots show a persistent marker chip; other
          // slots reveal it on hover to offer the "Expose slot" action.
          (exposed || isHovered || targetSlot === slotId) && (
            <div className={clsx(styles.canvasNameTag, styles.slotExposeChip)}>
              <DropdownMenu.Root>
                <DropdownMenu.Trigger>
                  <button
                    type="button"
                    className={clsx(styles.slotExposeChipButton, {
                      [styles.slotExposeChipButtonExposed]: isActiveExposed,
                    })}
                    aria-label={
                      exposed
                        ? `Exposed slot options for ${chipLabel}`
                        : `Slot options for ${slotName}`
                    }
                    data-testid={`slot-expose-chip-${slotId}`}
                  >
                    {exposed && <BoxIcon />}
                    <span>{chipLabel}</span>
                    <DotsHorizontalIcon />
                  </button>
                </DropdownMenu.Trigger>
                <SlotContextMenuContent
                  slot={slot}
                  parentComponent={parentComponent}
                  menuType="dropdown"
                />
              </DropdownMenu.Root>
            </div>
          )
        : (targetSlot === slotId || isHovered) && (
            <div
              className={clsx(styles.canvasNameTag, styles.canvasNameTagSlot)}
            >
              <SlotNameTag
                name={`${slotName} (${parentComponentName})`}
                id={slotId}
                nodeType={slot.nodeType}
              />
            </div>
          )}
      {!slot.components.length && !disableDrop && (
        <EmptySlotDropZone
          slot={slot}
          slotName={slotName}
          parentComponent={parentComponent}
        />
      )}

      {slot.components.map((childComponent: ComponentNode, index) => (
        <ComponentOverlay
          key={childComponent.uuid}
          parentSlot={slot}
          component={childComponent}
          index={index}
          disableDrop={disableDrop}
        />
      ))}

      {/* @todo - these SlotDropZones might become useful in future for handling more complex nested "container" components */}
      {/*{!disableDrop && (*/}
      {/*<SlotDropZone*/}
      {/*  slot={slot}*/}
      {/*  position="before"*/}
      {/*  size={size}*/}
      {/*  parentComponent={parentComponent}*/}
      {/*/>*/}
      {/*<SlotDropZone*/}
      {/*  slot={slot}*/}
      {/*  position="after"*/}
      {/*  size={size}*/}
      {/*  parentComponent={parentComponent}*/}
      {/*/>*/}
      {/*)}*/}
    </div>
  );
};

export default SlotOverlay;
