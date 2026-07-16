import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { BoxIcon, DotsHorizontalIcon } from '@radix-ui/react-icons';
import { DropdownMenu } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { findExposedSlotEntry } from '@/features/layout/exposedSlots';
import { selectExposedSlots } from '@/features/layout/layoutModelSlice';
import { useDataToHtmlMapValue } from '@/features/layout/preview/DataToHtmlMapContext';
import { SlotNameTag } from '@/features/layout/preview/NameTag';
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
import useSyncPreviewElementOffset from '@/hooks/useSyncPreviewElementOffset';
import useSyncPreviewElementSize from '@/hooks/useSyncPreviewElementSize';

import type React from 'react';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import styles from './PreviewOverlay.module.css';

// import SlotDropZone from '@/features/layout/previewOverlay/SlotDropZone';

export interface SlotOverlayProps {
  slot: SlotNode;
  iframeRef: React.RefObject<HTMLIFrameElement>;
  parentComponent: ComponentNode;
  disableDrop: boolean;
  forceRecalculate?: number; // Increment this prop to trigger a re-calculation of the slot overlay's border rect
}

const SlotOverlay: React.FC<SlotOverlayProps> = (props) => {
  const {
    slot,
    parentComponent,
    iframeRef,
    disableDrop,
    forceRecalculate = 0,
  } = props;
  const { componentsMap, slotsMap } = useDataToHtmlMapValue();
  const slotId = slot.id;
  const slotElementArray = useMemo(() => {
    const element = slotsMap[slot.id]?.element;
    return element ? [element] : null;
  }, [slotsMap, slot.id]);
  const { elementRect, recalculateBorder } =
    useSyncPreviewElementSize(slotElementArray);
  const parentElementsInsideIframe =
    componentsMap[parentComponent.uuid]?.elements;
  const { offset, recalculateOffset } = useSyncPreviewElementOffset(
    slotElementArray,
    parentElementsInsideIframe ? parentElementsInsideIframe : null,
  );
  // Padding calculation (if needed for visual reasons)
  const [padding, setPadding] = useState({
    paddingTop: '0px',
    paddingBottom: '0px',
  });
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const { setNonRoutedSelection } = useComponentSelection();
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, slotId);
  });
  const targetSlot = useAppSelector(selectTargetSlot);
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const slotName = useGetComponentName(slot, parentComponent);
  const parentComponentName = useGetComponentName(parentComponent);
  const [forceRecalculateChildren, setForceRecalculateChildren] = useState(0);

  // Template editor: exposed-slot marking + management controls.
  const dispatch = useAppDispatch();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const isTemplateContext = editorFrameContext === EditorFrameContext.TEMPLATE;
  const exposedSlots = useAppSelector(selectExposedSlots);
  const exposed = isTemplateContext
    ? findExposedSlotEntry(exposedSlots, parentComponent.uuid, slot.name)
    : null;
  const isActiveExposed = !!exposed;
  const chipLabel = exposed ? exposed.definition.label : slotName;

  // Per-content editing: exposed slots present as top-level slot regions, so
  // a nested slot node here is always ordinary entity-owned content.
  const isSlotSelected = slotId === selectedComponent;
  const visibleComponents = slot.components;
  const dropDisabled = disableDrop;

  useEffect(() => {
    const elementInsideIframe = slotsMap[slotId]?.element;
    if (elementInsideIframe) {
      const computedStyle = window.getComputedStyle(elementInsideIframe);
      setPadding({
        paddingTop: computedStyle.paddingTop,
        paddingBottom: computedStyle.paddingBottom,
      });
    }
  }, [slotsMap, slotId]);

  // Recalculate the children's borders when the elementRect changes
  useEffect(() => {
    setForceRecalculateChildren((prev) => prev + 1);
  }, [elementRect]);

  // Recalculate the border when the parent increments the forceRecalculate prop
  useEffect(() => {
    recalculateBorder();
    recalculateOffset();
  }, [forceRecalculate, recalculateBorder, recalculateOffset]);

  const style: React.CSSProperties = useMemo(
    () => ({
      height: elementRect.height * editorViewPortScale,
      width: elementRect.width * editorViewPortScale,
      top: (offset.offsetTop || 0) * editorViewPortScale,
      left: (offset.offsetLeft || 0) * editorViewPortScale,
      pointerEvents: 'none',
      ...padding,
    }),
    [
      elementRect.height,
      elementRect.width,
      editorViewPortScale,
      offset.offsetTop,
      offset.offsetLeft,
      padding,
    ],
  );

  if (!slotElementArray) {
    // If we can't find the element inside the iframe, don't render the overlay.
    return null;
  }

  return (
    <div
      aria-label={`${slotName} (${parentComponentName})`}
      className={clsx('slotOverlay', styles.slotOverlay, {
        [styles.selected]: isSlotSelected,
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
      {!visibleComponents.length && !dropDisabled && (
        <EmptySlotDropZone
          slot={slot}
          slotName={slotName}
          parentComponent={parentComponent}
        />
      )}

      {visibleComponents.map((childComponent: ComponentNode, index) => (
        <ComponentOverlay
          key={childComponent.uuid}
          iframeRef={iframeRef}
          parentSlot={slot}
          component={childComponent}
          index={index}
          disableDrop={dropDisabled}
          forceRecalculate={forceRecalculateChildren}
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
