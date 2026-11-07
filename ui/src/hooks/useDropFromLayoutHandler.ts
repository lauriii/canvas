import _ from 'lodash';

import { useAppDispatch, useAppSelector, useAppStore } from '@/app/hooks';
import { moveNode, selectLayout } from '@/features/layout/layoutModelSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import {
  getPreviewDocument,
  movePreviewComponent,
} from '@/features/layout/preview/optimisticPreview';

import type { DragEndEvent } from '@dnd-kit/core';

export function useDropFromLayoutHandler() {
  const dispatch = useAppDispatch();
  const store = useAppStore();
  const layout = useAppSelector(selectLayout);

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
    dispatch(moveNode({ uuid: activeUuid, to: dropPath }));
    // Show the move right away instead of after the new preview is rendered.
    // Only fall back to the 'pending move' state when that isn't possible.
    const previewDocument = getPreviewDocument();
    const moved =
      previewDocument &&
      movePreviewComponent(
        previewDocument,
        selectLayout(store.getState()),
        activeUuid,
      );
    afterDrag(elementsInsideIframe, !moved, activeUuid);
  }

  return { handleExistingDrop };
}
