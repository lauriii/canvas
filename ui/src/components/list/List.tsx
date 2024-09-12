import type React from 'react';
import { useEffect, useRef, useCallback } from 'react';
import styles from './List.module.css';
import menuStyles from '@/components/topbar/add/AddMenu.module.css';
import { selectDragging, setListDragging } from '@/features/ui/uiSlice';
import { setHidden, setInactive } from '@/features/ui/addMenuSlice';
import Sortable from 'sortablejs';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { Box, Flex, Spinner } from '@radix-ui/themes';
import clsx from 'clsx';
import * as Menubar from '@radix-ui/react-menubar';
import ListItem from '@/components/list/ListItem';
import type { LayoutModelSliceState } from '@/features/layout/layoutModelSlice';
import { isDropTargetInSlotAllowedByEdgeDistance } from '@/features/sortable/sortableUtils';

export interface ListProps {
  items: ListData | undefined;
  isLoading: boolean;
  type: 'component' | 'section';
  label: string;
}

export interface ListItemBase {
  id: string;
  name: string;
  metadata: Record<string, any>;
  default_markup: string;
}
export interface ComponentListItem extends ListItemBase {
  field_data: Record<string, any>;
}
export interface SectionListItem extends ListItemBase {
  layoutModel: LayoutModelSliceState;
}

interface ListData {
  [key: string]: ComponentListItem | SectionListItem;
}

const List: React.FC<ListProps> = (props) => {
  const { items, isLoading, type, label } = props;
  const dispatch = useAppDispatch();
  const sortableInstance = useRef<Sortable | null>(null);
  const listElRef = useRef<HTMLDivElement>(null);
  const { isDragging } = useAppSelector(selectDragging);

  const handleDragStart = useCallback(() => {
    dispatch(setListDragging(true));
    // When dragging a component, hide it instead of closing the menu, because we don't want
    // to unmount the draggable element from the DOM.
    dispatch(setHidden(true));
  }, [dispatch]);

  const handleDragClone = useCallback((ev: Sortable.SortableEvent) => {
    ev.clone.dataset.isNew = 'true';
  }, []);

  const handleDragEnd = useCallback(() => {
    dispatch(setListDragging(false));
    // After the drag ends, we can now close the menu without disrupting
    // the draggable functionality. Setting the primary menu to an empty string
    // closes it.
    dispatch(setInactive());
  }, [dispatch]);

  const handleDragMove = useCallback(
    (
      ev: Sortable.MoveEvent,
      originalEvent: Event | { clientX: number; clientY: number },
    ) => {
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
      <Menubar.Label className={menuStyles.MenubarLabel}>{label}</Menubar.Label>
      <Box pt="2" className={isDragging ? 'list-dragging' : ''}>
        <Spinner loading={isLoading}>
          <Flex direction="column" width="100%" ref={listElRef}>
            {items &&
              Object.entries(items).map(([id, item]) => (
                <ListItem item={item} key={id} type={type} />
              ))}
          </Flex>
        </Spinner>
      </Box>
    </div>
  );
};

export default List;
