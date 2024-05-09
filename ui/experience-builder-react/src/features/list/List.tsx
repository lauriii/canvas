import {useEffect, useRef, useState} from "react";
import {selectDragging, setListDragging, setTreeDragging} from "../../features/ui/uiSlice";
import Sortable from "sortablejs";
import {useAppDispatch, useAppSelector} from "../../app/hooks";

const List = () => {
  const dispatch = useAppDispatch();
  // const layout = useAppSelector(selectLayout);
  const sortableInstance = useRef<Sortable | null>(null)
  const listElRef = useRef<HTMLUListElement>(null);
  const { isDragging } = useAppSelector(selectDragging);

  function handleDragStart(ev: Sortable.SortableEvent) {
    dispatch(setListDragging(true));
  }

  function handleDragAdd(ev: Sortable.SortableEvent) {
    // updateData(ev, false);
  }

  function handleDragClone(ev: Sortable.SortableEvent) {
    ev.clone.dataset.isNew = 'true';
  }

  function handleDragEnd(ev: Sortable.SortableEvent) {
    dispatch(setListDragging(false));

    // Normally handle the data update in dragAdd unless the item is being dragged within the same container, in which
    // case dragAdd doesn't fire, so we can call it from here.
    if (ev.to === ev.from) {
      // updateData(ev, true);
    }
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
        onAdd: handleDragAdd,
        onStart: handleDragStart,
        onEnd: handleDragEnd,
        onClone: handleDragClone,
      });
    }
  }, []);

  return (
    <div className={isDragging ? "list-dragging" : ""}>
      <h2>Components</h2>
      <ul ref={listElRef}>
        <li data-xb-uuid="1">Component 1</li>
        <li data-xb-uuid="2">Component 2</li>
        <li data-xb-uuid="3">Component 3</li>
        <li data-xb-uuid="4">Component 4</li>
        <li data-xb-uuid="5">Component 5</li>
        <li data-xb-uuid="6">Component 6</li>
        <li data-xb-uuid="7">Component 7</li>
      </ul>
    </div>
  );
};

export default List;
