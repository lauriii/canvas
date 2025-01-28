import styles from './SortableContainer.module.css';
import type React from 'react';
import { useRef, useEffect } from 'react';
import Sortable from 'sortablejs';
import useSortable from '@/features/layout/layers/useSortable';

interface SortableContainerProps {
  children?: React.ReactNode;
  slotId: string;
  indent: number;
}

const SortableContainer: React.FC<SortableContainerProps> = (props) => {
  const { children, slotId, indent = 1 } = props;
  const sortableRef = useRef<HTMLDivElement | null>(null);
  const sortableInstance = useRef<Sortable | null>(null);
  const { handleDragAdd, handleDragStart, handleDragEnd } = useSortable();

  useEffect(() => {
    if (sortableRef?.current !== null) {
      sortableInstance.current = Sortable.create(
        sortableRef.current as HTMLDivElement,
        {
          dataIdAttr: 'data-xb-uuid',
          animation: 0,
          onAdd: handleDragAdd,
          invertSwap: true,
          handle: '.xb-drag-handle',
          ghostClass: styles.xbCustomGhost,
          onStart: () => {
            handleDragStart();
          },
          onEnd: (evt) => {
            handleDragEnd(evt);
          },
          group: {
            name: 'tree',
          },
          // Keep a clone element in the original position until the drag ends.
          removeCloneOnHide: false,
          onClone: (evt) => {
            evt.clone.classList.add(styles.xbCustomClone);
          },
          // Don't allow dragging a slot.
          filter: '[data-xb-type="slot"]',
        },
      );
    }
    return () => {
      if (sortableInstance.current instanceof Sortable) {
        sortableInstance.current.destroy();
      }
    };
  }, [handleDragAdd, handleDragEnd, handleDragStart]);

  return (
    <div
      className={styles.xbSortableContainer}
      ref={sortableRef}
      data-xb-slot-id={slotId}
      // @ts-ignore
      style={{ '--indent-depth': `${indent}` }}
    >
      {children}
    </div>
  );
};

export default SortableContainer;
