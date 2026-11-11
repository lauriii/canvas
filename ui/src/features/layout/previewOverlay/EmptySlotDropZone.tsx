import { useMemo } from 'react';
import clsx from 'clsx';
import { kebabCase } from 'lodash';
import { useDroppable } from '@dnd-kit/core';
import { BoxModelIcon } from '@radix-ui/react-icons';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { selectDragging } from '@/features/ui/uiSlice';
import useGetComponentName from '@/hooks/useGetComponentName';

import type React from 'react';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import styles from '@/features/layout/previewOverlay/PreviewOverlay.module.css';

export interface EmptySlotDropZoneProps {
  slot: SlotNode;
  slotName: string;
  parentComponent: ComponentNode;
}
const EmptySlotDropZone: React.FC<EmptySlotDropZoneProps> = (props) => {
  const { slot, slotName, parentComponent } = props;
  const layout = useAppSelector(selectLayout);
  // Only components from the preview or the library can be dropped here.
  const { previewDragging, listDragging } = useAppSelector(selectDragging);
  const acceptsActive = previewDragging || listDragging;
  const parentComponentName = useGetComponentName(parentComponent);

  const slotPath = useMemo(() => {
    const path = findNodePathByUuid(layout, slot.id);
    if (!path) {
      throw new Error(`Unable to ascertain 'path' to component ${slot.id}`);
    }
    // We want to drop into the first (0th) space in the empty slot.
    path.push(0);
    return path;
  }, [layout, slot.id]);

  const accepts = ['overlay', 'library'];

  const {
    setNodeRef: setDropRef,
    isOver,
    active,
  } = useDroppable({
    id: `${slot.id}`,
    disabled: !acceptsActive,
    data: {
      component: parentComponent,
      parentSlot: slot,
      path: slotPath,
      accepts,
    },
  });

  const activeName = isOver ? active?.data?.current?.name : '';

  return (
    <div className={styles.emptySlotContainer} data-testid="canvas-empty-slot">
      <div
        className={clsx(styles.emptySlotDropZone, {
          [styles.isOver]: isOver,
        })}
        data-testid={`canvas-empty-slot-drop-zone-${kebabCase(parentComponentName)}:${kebabCase(slotName)}`}
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
