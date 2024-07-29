import styles from './TreeParent.module.css';
import type React from 'react';
import { useRef, useEffect, useCallback } from 'react';
import Sortable from 'sortablejs';
import TreeChild from './TreeChild';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import type { LayoutNode } from '@/features/layout/layoutModelSlice';
import {
  selectLayout,
  addNewComponentToLayout,
  moveNode,
  sortNode,
} from '@/features/layout/layoutModelSlice';
import clsx from 'clsx';
import { setTreeDragging } from '@/features/ui/uiSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { useGetComponentsQuery } from '@/services/components';

interface TreeParentProps {
  node: LayoutNode;
}

const TreeParent: React.FC<TreeParentProps> = (props) => {
  const dispatch = useAppDispatch();
  const { node } = props;
  const { children } = node;
  const layout = useAppSelector(selectLayout);
  const { data: components } = useGetComponentsQuery();
  const componentsRef = useRef(components);

  const listElRef = useRef<HTMLUListElement>(null);
  const sortableInstance = useRef<Sortable | null>(null);

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
          ev.to.dataset.xbUuid,
        );
        if (receivingParentPath) {
          const newPath: number[] = [
            ...receivingParentPath,
            ev.newDraggableIndex,
          ];

          if (ev.clone.dataset.isNew === 'true' && ev.clone.dataset.xbUuid) {
            // When dragging a new element into the tree from the list, the clone is actually dropped into the DOM and we need
            // to remove it here.
            ev.item.remove();
            dispatch(
              addNewComponentToLayout({
                to: newPath,
                newNode: ev.clone.dataset.xbUuid,
                componentFieldData:
                  componentsRef?.current?.[ev.clone.dataset.xbUuid]?.[
                    'field_data'
                  ],
              }),
            );
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
        updateData(ev, true);
      }
    },
    [dispatch, updateData],
  );

  useEffect(() => {
    componentsRef.current = components;
  }, [components]);

  useEffect(() => {
    if (listElRef.current !== null) {
      sortableInstance.current = Sortable.create(listElRef.current, {
        dataIdAttr: 'data-xb-uuid',
        animation: 0,
        group: {
          name: 'tree',
          put: ['tree', 'list'],
        },
        ghostClass: styles.sortableGhost,
        onAdd: handleDragAdd,
        onStart: handleDragStart,
        onEnd: handleDragEnd,
      });
    }
  }, [layout, handleDragAdd, handleDragEnd, handleDragStart]);

  if (['root', 'slot'].includes(node.nodeType)) {
    return (
      <ul
        className={clsx(styles.treeParent, {
          [styles.listEmpty]: children.length === 0,
          [styles.slot]: node.nodeType === 'slot',
        })}
        ref={listElRef}
        data-xb-uuid={node.uuid}
      >
        {children.map((child) => (
          <TreeChild key={child.uuid} node={child} />
        ))}
      </ul>
    );
  } else if (node.children.length) {
    return (
      <ul
        className={`${styles.slotList} ${children.length === 0 ? styles.listEmpty : ''}`}
      >
        {children.map((child) => (
          <TreeChild key={child.uuid} node={child} />
        ))}
      </ul>
    );
  }
};

export default TreeParent;
