import { useRef, useEffect, useCallback } from 'react';
import type Sortable from 'sortablejs';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectLayout,
  moveNode,
  sortNode,
} from '@/features/layout/layoutModelSlice';
import { setTreeDragging } from '@/features/ui/uiSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { useGetComponentsQuery } from '@/services/components';

// Hooks for handling sortable drag and drop.
const useSortable = () => {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const { data: components } = useGetComponentsQuery();
  const componentsRef = useRef(components);

  const handleDragStart = useCallback(() => {
    dispatch(setTreeDragging(true));
  }, [dispatch]);

  const updateData = useCallback(
    (ev: Sortable.SortableEvent, sort: boolean) => {
      if (typeof ev.newDraggableIndex !== 'number') {
        return;
      }
      if (sort) {
        // Moving a node within the same parent.
        dispatch(
          sortNode({ uuid: ev.item.dataset.xbUuid, to: ev.newDraggableIndex }),
        );
      } else {
        // Moving a node from one parent to another
        const receivingParentPath = findNodePathByUuid(
          layout,
          ev.to.dataset.xbSlotId,
        );
        if (receivingParentPath) {
          const newPath: number[] = [
            ...receivingParentPath,
            ev.newDraggableIndex,
          ];

          if (ev.clone.dataset.isNew === 'true' && ev.clone.dataset.xbUuid) {
            // When dragging a new element into the tree from the list, the clone is actually dropped into the DOM and we need
            // to remove it here.
            console.error(
              'You dragged from the component list and dropped into the layers panel. This use case is not handled or tested...yet?',
            );
            // TODO - At the moment its not possible to drag items from the component list into the layers view
            //  - but it might be required in future.
            // ev.item.remove();
            // dispatch(
            //   addNewComponentToLayout({
            //     to: newPath,
            //     component: componentsRef?.current?.[ev.clone.dataset.xbUuid],
            //   }),
            // );
          } else {
            // When dragging, the element is actually moved in the DOM, after dragging we swap the original
            // item back so that the React Virtual DOM doesn't get out of sync when we update the data.
            const itemEl = ev.item; // dragged HTMLElement
            let origParent = ev.from;
            origParent.appendChild(itemEl);

            dispatch(moveNode({ uuid: ev.item.dataset.xbUuid, to: newPath }));
          }
        }
      }
    },
    [dispatch, layout],
  );

  const handleDragAdd = useCallback(
    (ev: Sortable.SortableEvent) => {
      updateData(ev, false);
    },
    [updateData],
  );

  const handleDragEnd = useCallback(
    (ev: Sortable.SortableEvent) => {
      dispatch(setTreeDragging(false));

      // Normally handle the data update in dragAdd unless the item is being dragged within the same container, in which
      // case dragAdd doesn't fire, so we can call it from here.
      if (ev.to === ev.from) {
        // Abort if the item is dropped in the same position.
        if (ev.oldIndex === ev.newIndex) {
          return;
        }
        updateData(ev, true);
      }
    },
    [dispatch, updateData],
  );

  useEffect(() => {
    componentsRef.current = components;
  }, [components]);

  return { handleDragAdd, handleDragEnd, handleDragStart };
};

export default useSortable;
