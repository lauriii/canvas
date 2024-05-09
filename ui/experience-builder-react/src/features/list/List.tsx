import {useEffect, useRef, useState} from "react";
import './List.css';
import {selectDragging, setListDragging, setTreeDragging} from "../../features/ui/uiSlice";
import Sortable from "sortablejs";
import {useAppDispatch, useAppSelector} from "../../app/hooks";

const List = () => {
  const dispatch = useAppDispatch();
  // const layout = useAppSelector(selectLayout);
  const sortableInstance = useRef<Sortable | null>(null)
  const listElRef = useRef<HTMLDivElement>(null);
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
        // fallbackTolerance: 3,
        // direction: 'horizontal',
        // multiDrag: false,
        // forceFallback: true,
        group: {
          name: "list",
          pull: 'clone',
          put: false,
          revertClone: true,
        },
        animation: 0,
        delay: 200,
        delayOnTouchOnly: true,
        onAdd: handleDragAdd,
        onStart: handleDragStart,
        onEnd: handleDragEnd,
        onClone: handleDragClone,
      });
    }
  }, []);


  //       sort: false,
  //       fallbackTolerance: 3,
  //       multiDrag: false,
  //       selectedClass: 'selected',
  //       animation: 150,
  //       forceFallback: true,
  //       dataIdAttr: 'data-component-uuid',
  //       fallbackOnBody: true,
  //       filter: '.ssa-no-drag',
  //       chosenClass: '',
  //       fallbackClass: 'ssa-sortable-placeholder',
  //       ghostClass: 'ssa-sortable-ghost',
  //       scroll: true, // Enable the plugin. Can be HTMLElement.
  //       scrollSensitivity: 150, // px, how near the mouse must be to an edge to start scrolling.
  //       scrollSpeed: 15, // px, speed of the scrolling
  //       bubbleScroll: false, // apply autoscroll to all parent elements, allowing for easier movement
  //       onStart: handleDragStart,
  //       onEnd: handleDragEnd,
  //       onMove: handleDragMove,
  //       delay: 200, // time in milliseconds to define when the sorting should start
  //       delayOnTouchOnly: true, // only delay if user is using touch

  return (
    <div className={isDragging ? "list-dragging" : ""}>
      <h2>Components</h2>
      <p><small>I'm not auto-generating unique uuid's so these start at 6 to not conflict with the components already present in the sample layout</small></p>
      <div ref={listElRef}>
        <div data-xb-uuid="6">Component 6</div>
        <div data-xb-uuid="7">Component 7</div>
        <div data-xb-uuid="8">Component 8</div>
        <div data-xb-uuid="9">Component 9</div>
        <div data-xb-uuid="10">Component 10</div>
        <div data-xb-uuid="11">Component 11</div>
      </div>
    </div>
  );
};

export default List;
