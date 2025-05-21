import type React from 'react';
import { useState } from 'react';
import { useEffect } from 'react';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import clsx from 'clsx';
import styles from '@/features/layout/previewOverlay/PreviewOverlay.module.css';
import { useDroppable } from '@dnd-kit/core';
import { useAppSelector } from '@/app/hooks';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { BoxModelIcon } from '@radix-ui/react-icons';

export interface EmptySlotDropZoneProps {
  slot: SlotNode;
  slotName: string;
  parentComponent: ComponentNode;
}
const EmptySlotDropZone: React.FC<EmptySlotDropZoneProps> = (props) => {
  const { slot, slotName, parentComponent } = props;
  const layout = useAppSelector(selectLayout);
  const [activeName, setActiveName] = useState('');

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
    id: `${slot.id}`,
    data: {
      component: parentComponent,
      parentSlot: slot,
      path: slotPath,
    },
  });

  useEffect(() => {
    if (isOver && active) {
      setActiveName(active.data?.current?.name);
    } else {
      setActiveName('');
    }
  }, [active, isOver]);

  return (
    <div className={styles.emptySlotContainer}>
      <div
        className={clsx(styles.emptySlotDropZone, {
          [styles.isOver]: isOver,
        })}
        data-testid="xb-empty-slot-drop-zone"
        ref={setDropRef}
      >
        {activeName ? (
          activeName
        ) : (
          <>
            <BoxModelIcon />
            <div>{slotName}</div>
          </>
        )}
      </div>
    </div>
  );
};

export default EmptySlotDropZone;
