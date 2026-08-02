import { useCallback, useMemo, useState } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { SlotNameTag } from '@/features/layout/preview/NameTag';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import EmptySlotDropZone from '@/features/layout/previewOverlay/EmptySlotDropZone';
import SlotAddControls from '@/features/layout/previewOverlay/SlotAddControls';
import {
  selectEditorViewPortScale,
  selectIsComponentHovered,
  selectTargetSlot,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
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
  const { componentId: selectedComponent } = useParams();
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, slotId);
  });
  const targetSlot = useAppSelector(selectTargetSlot);
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const slotName = useGetComponentName(slot, parentComponent);
  const parentComponentName = useGetComponentName(parentComponent);
  const dispatch = useAppDispatch();
  // Keep the tag up while the slot's own add menu is open, or choosing from
  // that menu would dismiss the thing being chosen from.
  const [isAddMenuOpen, setIsAddMenuOpen] = useState(false);

  // A slot reports its own hover, the same way a component does, so that
  // pointing at the space inside a container is pointing at *that slot* rather
  // than at the container as a whole. Components nested in the slot stop the
  // event first, so hovering one of them still belongs to the component.
  // @see \Drupal\canvas\ui\src\features\layout\previewOverlay\ComponentOverlay.tsx
  const handleSlotMouseOver = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      event.stopPropagation();
      dispatch(setHoveredComponent(slotId));
    },
    [dispatch, slotId],
  );

  const handleSlotMouseOut = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      event.stopPropagation();
      dispatch(unsetHoveredComponent());
    },
    [dispatch],
  );

  const style: React.CSSProperties = useMemo(
    () => ({
      height: (slotGeometry?.rect.height ?? 0) * editorViewPortScale,
      width: (slotGeometry?.rect.width ?? 0) * editorViewPortScale,
      top: offsetTop * editorViewPortScale,
      left: offsetLeft * editorViewPortScale,
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
      })}
      data-canvas-type="slot"
      style={style}
      onMouseOver={handleSlotMouseOver}
      onMouseOut={handleSlotMouseOut}
    >
      {(targetSlot === slotId || isHovered || isAddMenuOpen) && (
        <div className={clsx(styles.canvasNameTag, styles.canvasNameTagSlot)}>
          <SlotNameTag
            name={`${slotName} (${parentComponentName})`}
            id={slotId}
            nodeType={slot.nodeType}
            forceVisible={isAddMenuOpen}
            // A governed slot says how full it is and offers a way to fill it,
            // whether or not it already holds something.
            // @see \Drupal\canvas\SlotRestrictions
            trailing={
              disableDrop ? undefined : (
                <SlotAddControls
                  slot={slot}
                  onMenuOpenChange={setIsAddMenuOpen}
                />
              )
            }
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
