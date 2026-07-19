import _ from 'lodash';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { moveNode, selectLayout } from '@/features/layout/layoutModelSlice';
import {
  areConsecutiveSiblings,
  findNodePathByUuid,
  sortUuidsByDocumentOrder,
} from '@/features/layout/layoutUtils';
import { selectSelection } from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';

import type { DragEndEvent } from '@dnd-kit/core';
import type { AppThunk } from '@/app/store';

export function useDropFromLayoutHandler() {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const selection = useAppSelector(selectSelection);
  const { updateSelectionInRedux } = useComponentSelection();

  // There is an edge case where if an item is dragged into the space immediately after itself,
  // it's from and to position is not exactly the same, but the result is still that it doesn't
  // actually move - because it moves down one space past itself.
  function isLastElementIncremented(from: number[], to: number[]) {
    if (from.length !== to.length) {
      return false;
    }
    const lastIndex = from.length - 1;
    return (
      from.slice(0, lastIndex).every((value, index) => value === to[index]) &&
      to[lastIndex] === from[lastIndex] + 1
    );
  }

  // A consecutive group dropped anywhere inside or directly after its own run
  // ends up exactly where it started; treat that as a no-op so no undo entry
  // or preview round trip happens.
  function isDropIntoOwnPosition(sortedUuids: string[], dropPath: number[]) {
    if (!areConsecutiveSiblings(layout, sortedUuids)) {
      return false;
    }
    const firstPath = findNodePathByUuid(layout, sortedUuids[0]);
    if (!firstPath || firstPath.length !== dropPath.length) {
      return false;
    }
    if (!_.isEqual(firstPath.slice(0, -1), dropPath.slice(0, -1))) {
      return false;
    }
    const start = firstPath[firstPath.length - 1];
    const target = dropPath[dropPath.length - 1];
    return target >= start && target <= start + sortedUuids.length;
  }

  function handleExistingDrop(event: DragEndEvent, afterDrag: Function) {
    const activeComponent = event.active.data?.current?.component;
    const activeUuid = activeComponent.uuid;
    const elementsInsideIframe =
      event.active.data?.current?.elementsInsideIframe || [];
    if (!event.over) {
      afterDrag(elementsInsideIframe, false);
      return;
    }
    const dropPath = event.over.data?.current?.path;
    if (!dropPath) {
      // The component we are dropping onto was not found. I don't think this can happen, but if it does, do nothing.
      afterDrag(elementsInsideIframe, false);
      return;
    }

    // Dragging a member of the current multi-selection moves the whole
    // selection: the selected components are inserted contiguously at the drop
    // position, in document order, as one atomic operation. Dragging a
    // non-member moves only the dragged component.
    const sortedSelection = sortUuidsByDocumentOrder(layout, selection.items);
    const isMultiDrag =
      sortedSelection.length > 1 && sortedSelection.includes(activeUuid);

    if (isMultiDrag) {
      // A drop target inside a selected component's own subtree would be
      // deleted along with the original when the group moves; ignore it. The
      // dragged component's own drop zones are already disabled by dnd-kit,
      // but the other selected components' are not.
      const isDropInsideSelection = sortedSelection.some((uuid) => {
        const memberPath = findNodePathByUuid(layout, uuid);
        return (
          memberPath &&
          dropPath.length > memberPath.length &&
          _.isEqual(dropPath.slice(0, memberPath.length), memberPath)
        );
      });
      if (
        isDropInsideSelection ||
        isDropIntoOwnPosition(sortedSelection, dropPath)
      ) {
        afterDrag(elementsInsideIframe, false);
        return;
      }
      afterDrag(elementsInsideIframe, true, activeUuid);
      dispatch(moveNode({ uuid: sortedSelection, to: dropPath }));
      // Reselect the moved components against the post-move layout: a
      // non-consecutive selection becomes consecutive once it is dropped as a
      // contiguous group, which re-enables the group actions.
      const reselectMoved: AppThunk = (_dispatch, getState) => {
        updateSelectionInRedux(sortedSelection, selectLayout(getState()));
      };
      dispatch(reselectMoved);
      return;
    }

    const currentPath = findNodePathByUuid(layout, activeUuid);
    if (!currentPath) {
      throw new Error(`Unable to ascertain current path of dragged element.`);
    }
    if (
      _.isEqual(currentPath, dropPath) ||
      isLastElementIncremented(currentPath, dropPath)
    ) {
      // The dragged item was dropped back where it came from. Do nothing.
      afterDrag(elementsInsideIframe, false);
      return;
    }

    // if we got this far, then we have a valid location to move the dragged component to!
    // @todo We should optimistically move the elementsInsideIframe to the new location in the iFrames dom.
    // for now, we pass true here which will put the elementsInsideIframe into a 'pending move' state.
    afterDrag(elementsInsideIframe, true, activeUuid);
    dispatch(moveNode({ uuid: activeUuid, to: dropPath }));
  }

  return { handleExistingDrop };
}
