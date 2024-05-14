import {useEffect, useRef, useState} from "react";
import './List.css';
import {selectDragging, setListDragging, setTreeDragging} from "../../features/ui/uiSlice";
import Sortable from "sortablejs";
import {useAppDispatch, useAppSelector} from "../../app/hooks";
import { useGetComponentsQuery} from "../../services/components";

const List = () => {
  const dispatch = useAppDispatch();
  const { data: components, error, isLoading } = useGetComponentsQuery();
  const sortableInstance = useRef<Sortable | null>(null)
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
        dataIdAttr: "data-xb-uuid",
        sort: false,
        group: {
          name: "list",
          pull: 'clone',
          put: false,
          revertClone: true,
        },
        animation: 0,
        delay: 200,
        delayOnTouchOnly: true,
        onStart: handleDragStart,
        onEnd: handleDragEnd,
        onClone: handleDragClone
      });
    }
  }, []);


  return (
    <div className={isDragging ? "list-dragging" : ""}>
      <h2>Components</h2>
      <p><small>I'm not auto-generating unique uuid's so these start at 6 to not conflict with the components already present in the sample layout</small></p>
      <div ref={listElRef}>
        {/*
         TODO: I've not figured out how to make this work as a UL/LI list as dragging LI elements into the preview doesn't work
         as an LI being dropped into a DIV is invalid and breaks the sortable newDraggableIndex value
        */}

        {error && <div>{
          // @ts-ignore
          error?.error
        }</div>}
        {isLoading && <div>Loading</div>}
        {components && components.map((component)=> (
          <div key={component.id} data-xb-uuid={component.id} data-xb-name={component.name}>{component.name}</div>
        ))}
      </div>
    </div>
  );
};

export default List;
