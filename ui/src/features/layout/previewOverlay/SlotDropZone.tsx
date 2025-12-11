import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { useDroppable } from '@dnd-kit/core';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';

import type React from 'react';
import type {
  ComponentNode,
  RegionNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import styles from '@/features/layout/previewOverlay/PreviewOverlay.module.css';

export interface SlotDropZoneProps {
  slot: SlotNode;
  position: 'before' | 'after';
  parentComponent?: ComponentNode;
  parentRegion?: RegionNode;
}
const SlotDropZone: React.FC<SlotDropZoneProps> = (props) => {
  const { slot, position, parentRegion, parentComponent } = props;
  const layout = useAppSelector(selectLayout);
  const [activeOrigin, setActiveOrigin] = useState('');
  const accepts = ['overlay', 'library'];

  const slotPath = findNodePathByUuid(layout, slot.id);
  if (!slotPath) {
    throw new Error(`Unable to ascertain 'path' to component ${slot.id}`);
  }
  // We want to drop into the first (0th) space in the empty slot.
  slotPath.push(0);

  const {
    setNodeRef: setDropRef,
    isOver,
    active,
  } = useDroppable({
    id: `${slot.id}_${position}`,
    disabled: !accepts.includes(activeOrigin),
    data: {
      slot: slot,
      component: parentComponent,
      position: position,
      parentRegion: parentRegion,
      path: slotPath,
      accepts,
    },
  });

  useEffect(() => {
    if (active) {
      setActiveOrigin(active.data?.current?.origin);
    } else {
      setActiveOrigin('');
    }
  }, [active]);

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
