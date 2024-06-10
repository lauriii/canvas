import { useEffect, useRef, useState } from 'react';
import styles from './List.module.css';
import {
  selectDragging,
  setListDragging,
  setTreeDragging,
} from '@/features/ui/uiSlice';
import Sortable from 'sortablejs';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useGetComponentsQuery } from '@/services/components';
import { Box, Card, Flex,  Spinner, Text } from '@radix-ui/themes';
import { customSortableDragImage } from '@/features/sortable/sortableUtils';

const List = () => {
  const dispatch = useAppDispatch();
  const { data: components, error, isLoading } = useGetComponentsQuery();
  const sortableInstance = useRef<Sortable | null>(null);
  const listElRef = useRef<HTMLDivElement>(null);
  const { isDragging } = useAppSelector(selectDragging);

  function handleDragStart(ev: Sortable.SortableEvent) {
    dispatch(setListDragging(true));
  }

  function handleDragClone(ev: Sortable.SortableEvent) {
    ev.clone.dataset.isNew = 'true';
  }

  function handleDragEnd(ev: Sortable.SortableEvent) {
    dispatch(setListDragging(false));
  }

  useEffect(() => {
    if (listElRef.current !== null) {
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
      });
    }
  }, [isLoading]);

  return (
    <Box pt="5" className={isDragging ? 'list-dragging' : ''}>
      <Spinner loading={isLoading}>
        <Flex gap="2" direction="column" width="100%" ref={listElRef}>
          {/*
         TODO: I've not figured out how to make this work as a UL/LI list as dragging LI elements into the preview doesn't work
         as an LI being dropped into a DIV is invalid and breaks the sortable newDraggableIndex value
        */}

          {error && (
            <div className="error">
              {
                // @ts-ignore
                error?.error
              }
            </div>
          )}
          {components &&
            components.map((component) => (
              <Card
                variant="surface"
                size="1"
                key={component.id}
                data-xb-uuid={component.id}
                data-xb-name={component.name}
                onDragStart={(event) =>
                  customSortableDragImage(
                    event,
                    window.document,
                    component.name,
                  )
                }
              >
                <Text>{component.name}</Text>
              </Card>
            ))}
        </Flex>
      </Spinner>
    </Box>
  );
};

export default List;
