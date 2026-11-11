import { useMemo } from 'react';
import clsx from 'clsx';
import { useDroppable } from '@dnd-kit/core';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { selectDragging } from '@/features/ui/uiSlice';

import type React from 'react';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import styles from '@/features/layout/previewOverlay/PreviewOverlay.module.css';

export interface SlotDropZoneProps {
  slot: SlotNode;
  position: 'before' | 'after';
  parentComponent?: ComponentNode;
}
const SlotDropZone: React.FC<SlotDropZoneProps> = (props) => {
  const { slot, position, parentComponent } = props;
  const layout = useAppSelector(selectLayout);
  // Only components from the preview or the library can be dropped here.
  const { previewDragging, listDragging } = useAppSelector(selectDragging);
  const acceptsActive = previewDragging || listDragging;
  const accepts = ['overlay', 'library'];

  const slotPath = useMemo(() => {
    const path = findNodePathByUuid(layout, slot.id);
    if (!path) {
      throw new Error(`Unable to ascertain 'path' to component ${slot.id}`);
    }
    // We want to drop into the first (0th) space in the empty slot.
    path.push(0);
    return path;
  }, [layout, slot.id]);

  const { setNodeRef: setDropRef, isOver } = useDroppable({
    id: `${slot.id}_${position}`,
    disabled: !acceptsActive,
    data: {
      slot: slot,
      component: parentComponent,
      position: position,
      path: slotPath,
      accepts,
    },
  });

  const dropzoneStyle = styles[position];

  return (
    <div
      className={clsx(styles.slotDropZone, dropzoneStyle, {
        [styles.isOver]: isOver,
      })}
      ref={setDropRef}
    ></div>
  );
};

export default SlotDropZone;
