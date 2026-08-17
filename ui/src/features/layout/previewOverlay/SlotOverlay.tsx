import { useMemo } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';

import { useAppSelector } from '@/app/hooks';
import { SlotNameTag } from '@/features/layout/preview/NameTag';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import EmptySlotDropZone from '@/features/layout/previewOverlay/EmptySlotDropZone';
import {
  selectEditorViewPortScale,
  selectIsComponentHovered,
  selectTargetSlot,
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
      aria-label={Drupal.t('!slot (!component)', {
        '!slot': slotName,
        '!component': parentComponentName,
      })}
      className={clsx('slotOverlay', styles.slotOverlay, {
        [styles.selected]: slotId === selectedComponent,
        [styles.hovered]: isHovered,
        [styles.dropTarget]: slotId === targetSlot,
      })}
      data-canvas-type="slot"
      style={style}
    >
      {(targetSlot === slotId || isHovered) && (
        <div className={clsx(styles.canvasNameTag, styles.canvasNameTagSlot)}>
          <SlotNameTag
            name={Drupal.t('!slot (!component)', {
              '!slot': slotName,
              '!component': parentComponentName,
            })}
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
