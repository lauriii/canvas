import type React from 'react';
import { useState } from 'react';
import { DragOverlay, useDndMonitor } from '@dnd-kit/core';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  addNewComponentToLayout,
  moveNode,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import styles from './PreviewOverlay.module.css';
import {
  setListDragging,
  setPreviewDragging,
  setTargetSlot,
  unsetTargetSlot,
} from '@/features/ui/uiSlice';
import _ from 'lodash';
import { useNavigationUtils } from '@/hooks/useNavigationUtils';

const DragEventsHandler: React.FC = () => {
  const layout = useAppSelector(selectLayout);
  const dispatch = useAppDispatch();
  const [componentName, setComponentName] = useState('...');
  const { setSelectedComponent } = useNavigationUtils();

  const afterDrag = (elements: HTMLElement[] = [], successful?: boolean) => {
    elements.forEach((el) => {
      el.classList.remove('xb--item-dragging');
      if (successful) {
        el.classList.add('xb--item-updating');
      }
    });
  };

  // There is an edge case where if an item is dragged into the space immediately after itself,
  // it's from and to position is not exactly the same, but the result is still that it doesn't
  // actually move - because it moves down one space past itself.
  const isLastElementIncremented = (from: number[], to: number[]) => {
    if (from.length !== to.length) {
      return false;
    }
    const lastIndex = from.length - 1;
    return (
      from.slice(0, lastIndex).every((value, index) => value === to[index]) &&
      to[lastIndex] === from[lastIndex] + 1
    );
  };

  const getOrigin = (event: any): 'library' | 'overlay' | 'unknown' => {
    if (event.active?.data?.current?.origin) {
      return event.active.data.current.origin;
    } else {
      return 'unknown';
    }
  };

  useDndMonitor({
    onDragStart(event) {
      setComponentName(event.active.data?.current?.name);
      if (getOrigin(event) === 'overlay') {
        dispatch(setPreviewDragging(true));
        const elementsInsideIframe =
          event.active.data?.current?.elementsInsideIframe;
        if (elementsInsideIframe) {
          elementsInsideIframe.forEach((el: HTMLElement) => {
            el.classList.add('xb--item-dragging');
          });
        }
      } else if (getOrigin(event) === 'library') {
        dispatch(setListDragging(true));
      }
    },
    onDragOver(event) {
      const parentSlot = event.over?.data?.current?.parentSlot;
      const parentRegion = event.over?.data?.current?.parentRegion;

      if (parentRegion) {
        dispatch(setTargetSlot(parentRegion.id));
      } else if (parentSlot) {
        dispatch(setTargetSlot(parentSlot.id));
      }
    },
    onDragEnd: function (event) {
      dispatch(setPreviewDragging(false));
      dispatch(setListDragging(false));
      dispatch(unsetTargetSlot());

      const elementsInsideIframe =
        event.active.data?.current?.elementsInsideIframe || [];

      if (!event.over) {
        // If the dragged item wasn't dropped into a dropZone, do nothing.
        afterDrag(elementsInsideIframe);
        return;
      }

      if (getOrigin(event) === 'overlay') {
        const activeComponent = event.active.data?.current?.component;
        const activeUuid = activeComponent.uuid;

        const dropPath = event.over.data?.current?.path;
        if (!dropPath) {
          // The component we are dropping onto was not found. I don't think this can happen, but if it does, do nothing.
          afterDrag(elementsInsideIframe);
          return;
        }
        const currentPath = findNodePathByUuid(layout, activeUuid);
        if (!currentPath) {
          throw new Error(
            `Unable to ascertain current path of dragged element.`,
          );
        }

        if (
          _.isEqual(currentPath, dropPath) ||
          isLastElementIncremented(currentPath, dropPath)
        ) {
          // The dragged item was dropped back where it came from. Do nothing.
          afterDrag(elementsInsideIframe);
          return;
        }

        // if we got this far, then we have a valid location to move the dragged component to!
        // @todo We should optimistically move the elementsInsideIframe to the new location in the iFrames dom.
        // for now, we pass true here which will put the elementsInsideIframe into a 'pending move' state.
        afterDrag(elementsInsideIframe, true);

        dispatch(
          moveNode({
            uuid: activeUuid,
            to: dropPath,
          }),
        );
      } else if (getOrigin(event) === 'library') {
        const newItem = event.active.data?.current?.item;
        const dropPath = event.over.data?.current?.path;
        if (!dropPath) {
          // The component we are dropping onto was not found. I don't think this can happen, but if it does, do nothing.
          return;
        }

        // @todo We should optimistically insert newItem.default_markup into to the new location in the iFrames dom.
        dispatch(
          addNewComponentToLayout(
            {
              to: dropPath,
              component: newItem,
            },
            setSelectedComponent,
          ),
        );
      }
    },
    onDragCancel(event) {
      dispatch(setPreviewDragging(false));
      dispatch(setListDragging(false));
      dispatch(unsetTargetSlot());
      const elementsInsideIframe =
        event.active.data?.current?.elementsInsideIframe || [];

      elementsInsideIframe.forEach((el: HTMLElement) => {
        el.classList.remove('xb--item-dragging');
      });
    },
  });

  return (
    <DragOverlay className={styles.dragOverlay} dropAnimation={null}>
      <div>{componentName}</div>
    </DragOverlay>
  );
};

export default DragEventsHandler;
