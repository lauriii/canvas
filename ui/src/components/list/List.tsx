import type React from 'react';
import { useEffect, useRef, useCallback, useMemo } from 'react';
import styles from './List.module.css';
import {
  selectDragging,
  setListDragging,
  unsetTargetSlot,
} from '@/features/ui/uiSlice';
import Sortable from 'sortablejs';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { Box, Flex, Spinner } from '@radix-ui/themes';
import clsx from 'clsx';
import ListItem from '@/components/list/ListItem';
import {
  isDropTargetBetweenTwoElementsOfSameComponent,
  isDropTargetInSlotAllowedByEdgeDistance,
} from '@/features/sortable/sortableUtils';
import type { ComponentsList } from '@/types/Component';
import type { SectionsList } from '@/types/Section';
import { v4 as uuidv4 } from 'uuid';
import { setUniqueListId } from '@/features/ui/primaryPanelSlice';

export interface ListProps {
  items: ComponentsList | SectionsList | undefined;
  isLoading: boolean;
  type: 'component' | 'section';
  label: string;
}

const List: React.FC<ListProps> = (props) => {
  const { items, isLoading, type } = props;
  const dispatch = useAppDispatch();
  const sortableInstance = useRef<Sortable | null>(null);
  const listElRef = useRef<HTMLDivElement>(null);
  const { isDragging } = useAppSelector(selectDragging);

  // Sort items and convert to array.
  const sortedItems = useMemo(() => {
    return items
      ? Object.entries(items).sort(([, a], [, b]) =>
          a.name.localeCompare(b.name),
        )
      : [];
  }, [items]);

  const handleDragStart = useCallback(() => {
    dispatch(setListDragging(true));
  }, [dispatch]);

  const handleDragClone = useCallback((ev: Sortable.SortableEvent) => {
    ev.clone.dataset.isNew = 'true';
  }, []);

  const handleDragEnd = useCallback(() => {
    dispatch(setListDragging(false));
    dispatch(unsetTargetSlot());
    // SortableJS replaces the dragged item with a clone. However, the clone doesn't have the events and react bindings that
    // the original item has.
    //
    // Here we generate a unique ID at the end of the drag operation. This is used as a key to the parent list component
    // which triggers a re-render so the items in the list are re-rendered with the correct event handlers and bindings.
    const id = uuidv4();
    dispatch(setUniqueListId(id));
  }, [dispatch]);

  const handleDragMove = useCallback(
    (
      ev: Sortable.MoveEvent,
      originalEvent: Event | { clientX: number; clientY: number },
    ) => {
      if (isDropTargetBetweenTwoElementsOfSameComponent(ev)) {
        return false;
      }
      // Prevent placing a component by dragging too close to the top or bottom edge of a slot.
      return isDropTargetInSlotAllowedByEdgeDistance(ev, originalEvent);
    },
    [],
  );

  useEffect(() => {
    if (
      !isLoading &&
      listElRef.current !== null &&
      !sortableInstance.current?.el
    ) {
      sortableInstance.current = Sortable.create(listElRef.current, {
        dataIdAttr: 'data-xb-uuid',
        sort: false,
        group: {
          name: 'list',
          pull: 'clone',
          put: false,
          revertClone: true,
        },
        animation: 0,
        delay: 200,
        delayOnTouchOnly: true,
        ghostClass: styles.sortableGhost,
        onStart: handleDragStart,
        onEnd: handleDragEnd,
        onClone: handleDragClone,
        onMove: handleDragMove,
      });
    }
    return () => {
      if (sortableInstance.current !== null) {
        sortableInstance.current.destroy();
      }
    };
  }, [
    isLoading,
    handleDragStart,
    handleDragEnd,
    handleDragClone,
    handleDragMove,
  ]);

  return (
    <div className={clsx('listContainer', styles.listContainer)}>
      <Box className={isDragging ? 'list-dragging' : ''}>
        <Spinner loading={isLoading}>
          <Flex direction="column" width="100%" ref={listElRef}>
            {sortedItems &&
              sortedItems.map(([id, item]) => (
                <ListItem item={item} key={id} type={type} />
              ))}
          </Flex>
        </Spinner>
      </Box>
    </div>
  );
};

export default List;
