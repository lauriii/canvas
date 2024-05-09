import "./TreeParent.css";
import type React from "react";
import { useRef, useEffect, useCallback, useState } from "react";
import Sortable from "sortablejs";
import TreeChild from "./TreeChild";
import { useAppDispatch, useAppSelector } from "../../../app/hooks";
import {insertNode, LayoutNode} from "../layoutSlice";
import { selectLayout } from "../layoutSlice";
import { moveNode, sortNode, setNewLayout } from "../layoutSlice";
import { setTreeDragging } from "../../ui/uiSlice";
import { findNodePathByUuid } from "../layoutUtils";

interface TreeParentProps {
  node: LayoutNode;
}

const TreeParent: React.FC<TreeParentProps> = props => {
  const dispatch = useAppDispatch();
  const { node } = props;
  const { children } = node;
  const layout = useAppSelector(selectLayout);
  const listElRef = useRef<HTMLUListElement>(null);
  const sortableInstance = useRef<Sortable | null>(null);

  function handleDragStart(ev: Sortable.SortableEvent) {
    dispatch(setTreeDragging(true));
  }

  function handleDragAdd(ev: Sortable.SortableEvent) {
    updateData(ev, false);
  }

  function handleDragEnd(ev: Sortable.SortableEvent) {
    dispatch(setTreeDragging(false));

    // Normally handle the data update in dragAdd unless the item is being dragged within the same container, in which
    // case dragAdd doesn't fire, so we can call it from here.
    if (ev.to === ev.from) {
      updateData(ev, true);
    }
  }

  function updateData(ev: Sortable.SortableEvent, sort: boolean) {
    if (typeof ev.newDraggableIndex !== 'number') {
      return;
    }
    if (sort) {
      // Moving a node within the same parent.
      dispatch(sortNode({ uuid: ev.item.dataset.xbUuid, to: ev.newDraggableIndex }));
    } else {
      // Moving a node from one parent to another
      const receivingParentPath = findNodePathByUuid(layout, ev.to.dataset.xbUuid);
      if (receivingParentPath) {
        const newPath: number[] = [...receivingParentPath, ev.newDraggableIndex];

        if(ev.clone.dataset.isNew === 'true' && ev.clone.dataset.xbUuid) {
          // When dragging a new element into the tree from the list, the clone is actually dropped into the DOM and we need
          // to remove it here.
          ev.item.remove();
          dispatch(insertNode({to: newPath, newNode: {uuid: ev.clone.dataset.xbUuid, children: [], type: 'component', name: `Component ${ev.clone.dataset.xbUuid}`}}))
        } else {
          // When dragging, the element is actually moved in the DOM, after dragging we swap the original
          // item back so that React's Virtual DOM doesn't get out of sync when we update the data.
          const itemEl = ev.item; // dragged HTMLElement
          let origParent = ev.from;
          origParent.appendChild(itemEl);

          dispatch(moveNode({ uuid: ev.item.dataset.xbUuid, to: newPath }));
        }
      }
    }
  }

  useEffect(() => {
    if (listElRef.current !== null) {
      sortableInstance.current = Sortable.create(listElRef.current, {
        dataIdAttr: "data-xb-uuid",
        animation: 0,
        group: {
          name: "tree",
          put: ["tree", "list"],
        },
        onAdd: handleDragAdd,
        onStart: handleDragStart,
        onEnd: handleDragEnd,
      });
    }
  }, [layout]);

  if(node.type === 'slot' || node.type === 'root') {
    return (
      <ul className={`treeParent slot ${children.length === 0 ? "list-empty" : ""}`} ref={listElRef} data-xb-uuid={node.uuid}>
        {children.map(child => (
          <TreeChild key={child.uuid} node={child} />
        ))}
      </ul>
    );
  } else if (node.children.length) {
    return (
      <ul className={`treeParent ${children.length === 0 ? "list-empty" : ""}`}>
        {children.map(child => (
          <TreeChild key={child.uuid} node={child} />
        ))}
      </ul>
    );
  }


};

export default TreeParent;
