import { useEffect, useRef, useCallback } from 'react';
import styles from './List.module.css';
import menuStyles from '@/components/sidebar/primary/PrimaryMenubar.module.css';
import {
  selectDragging,
  setListDragging,
  setPrimaryMenuActiveMenu,
  setPrimaryMenuHidden,
} from '@/features/ui/uiSlice';
import Sortable from 'sortablejs';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useGetComponentsQuery } from '@/services/components';
import { Box, Flex, Spinner, Text } from '@radix-ui/themes';
import { customSortableDragImage } from '@/features/sortable/sortableUtils';
import clsx from 'clsx';
import * as Menubar from '@radix-ui/react-menubar';

const List = () => {
  const dispatch = useAppDispatch();
  const { data: components, error, isLoading } = useGetComponentsQuery();
  const sortableInstance = useRef<Sortable | null>(null);
  const listElRef = useRef<HTMLDivElement>(null);
  const { isDragging } = useAppSelector(selectDragging);

  const handleDragStart = useCallback(() => {
    dispatch(setListDragging(true));
    // When dragging a component, hide it instead of closing the menu, because we don't want
    // to unmount the draggable element from the DOM.
    dispatch(setPrimaryMenuHidden(true));
  }, [dispatch]);

  const handleDragClone = useCallback((ev: Sortable.SortableEvent) => {
    ev.clone.dataset.isNew = 'true';
  }, []);

  const handleDragEnd = useCallback(() => {
    dispatch(setListDragging(false));
    // After the drag ends, we can now close the menu without disrupting
    // the draggable functionality. Setting the primary menu to an empty string
    // closes it.
    dispatch(setPrimaryMenuActiveMenu(''));
  }, [dispatch]);

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
      });
    }
    return () => {
      if (sortableInstance.current !== null) {
        sortableInstance.current.destroy();
      }
    };
  }, [isLoading, handleDragStart, handleDragEnd, handleDragClone]);

  return (
    <div className={clsx('listContainer', styles.listContainer)}>
      <Menubar.Label className={menuStyles.MenubarLabel}>Basic</Menubar.Label>
      <Box pt="2" className={isDragging ? 'list-dragging' : ''}>
        <Spinner loading={isLoading}>
          <Flex direction="column" width="100%" ref={listElRef}>
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
                <div
                  key={component.id}
                  data-xb-uuid={component.id}
                  data-xb-name={component.name}
                  className={clsx(
                    'listItem',
                    styles.listItem,
                    menuStyles.MenubarItem,
                  )}
                  onDragStart={(event) =>
                    customSortableDragImage(
                      event,
                      window.document,
                      component.name,
                    )
                  }
                >
                  <Text>{component.name}</Text>
                </div>
              ))}
          </Flex>
        </Spinner>
      </Box>
    </div>
  );
};

export default List;
