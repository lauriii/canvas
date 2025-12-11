import { useState } from 'react';
import clsx from 'clsx';
import { DragOverlay, useDndMonitor } from '@dnd-kit/core';
import {
  restrictToFirstScrollableAncestor,
  restrictToWindowEdges,
} from '@dnd-kit/modifiers';

import { useAppDispatch } from '@/app/hooks';
import {
  setCodeDragging,
  setListDragging,
  setPreviewDragging,
  setTargetSlot,
  setTreeDragging,
  setUpdatingComponent,
  unsetTargetSlot,
} from '@/features/ui/uiSlice';
import { useDropFromLayoutHandler } from '@/hooks/useDropFromLayoutHandler';
import { useDropFromLibraryHandler } from '@/hooks/useDropFromLibraryHandler';
import { useDropOnFolderHandler } from '@/hooks/useDropOnFolderHandler';

import {
  cleanupMouseTracking,
  initMouseTracking,
  snapRightToCursor,
} from './snapRightToCursor';

import type React from 'react';
import type {
  DragEndEvent,
  DragOverEvent,
  DragStartEvent,
} from '@dnd-kit/core';

import styles from './DragOverlay.module.css';

const DragEventsHandler: React.FC = () => {
  const dispatch = useAppDispatch();
  const [componentName, setComponentName] = useState('...');
  const [dragOrigin, setDragOrigin] = useState('');
  const { handleFolderDrop } = useDropOnFolderHandler();
  const { handleNewDrop } = useDropFromLibraryHandler();
  const { handleExistingDrop } = useDropFromLayoutHandler();

  const afterDrag = (
    elements: HTMLElement[] = [],
    successful?: boolean,
    componentUuid?: string,
  ) => {
    if (successful && componentUuid) {
      dispatch(setUpdatingComponent(componentUuid));
    }
  };

  const getOrigin = (
    event: any,
  ): 'library' | 'overlay' | 'layers' | 'code' | 'unknown' => {
    if (event.active?.data?.current?.origin) {
      return event.active.data.current.origin;
    } else {
      return 'unknown';
    }
  };

  const modifiers = ['layers', 'code'].includes(dragOrigin)
    ? [snapRightToCursor, restrictToFirstScrollableAncestor]
    : [snapRightToCursor, restrictToWindowEdges];

  function handleDragStart(event: DragStartEvent) {
    initMouseTracking();
    setComponentName(event.active.data?.current?.name);
    window.document.body.classList.add(styles.dragging);
    const origin = getOrigin(event);
    setDragOrigin(origin);
    if (origin === 'overlay') {
      dispatch(setPreviewDragging(true));
    } else if (origin === 'library') {
      dispatch(setListDragging(true));
    } else if (origin === 'layers') {
      dispatch(setTreeDragging(true));
    } else if (origin === 'code') {
      dispatch(setCodeDragging(true));
    }
  }

  function handleDragOver(event: DragOverEvent) {
    const { over } = event;
    const parentSlot = over?.data?.current?.parentSlot;
    const parentRegion = over?.data?.current?.parentRegion;

    if (parentRegion) {
      dispatch(setTargetSlot(parentRegion.id));
    } else if (parentSlot) {
      dispatch(setTargetSlot(parentSlot.id));
    }
  }

  function dragEndCancelCommon() {
    dispatch(setPreviewDragging(false));
    dispatch(setListDragging(false));
    dispatch(setTreeDragging(false));
    dispatch(setCodeDragging(false));
    dispatch(unsetTargetSlot());
    window.document.body.classList.remove(styles.dragging);

    // Ensure the mouse tracking is cleaned up
    cleanupMouseTracking();
  }

  function handleDragEnd(event: DragEndEvent) {
    dragEndCancelCommon();
    const { over, active } = event;
    const elementsInsideIframe =
      active.data?.current?.elementsInsideIframe || [];
    if (!over) {
      // If the dragged item wasn't dropped into a valid dropZone, do nothing.
      afterDrag(elementsInsideIframe, false);
      return;
    }
    const origin = getOrigin(event);

    if (
      over.data?.current?.destination === 'folder' &&
      ['library', 'code'].includes(origin)
    ) {
      // Handle drop into folder from library
      handleFolderDrop(event);
    } else if (
      origin === 'overlay' ||
      over.data.current?.destination === 'layers'
    ) {
      // Handle dropping an existing instance back into layout from overlay or layers panel
      handleExistingDrop(event, afterDrag);
    } else if (origin === 'library') {
      // Handle dropping a new component/pattern etc. from library into layout
      handleNewDrop(event);
    }
  }

  function handleDragCancel() {
    dragEndCancelCommon();
  }

  useDndMonitor({
    onDragStart: handleDragStart,
    onDragOver: handleDragOver,
    onDragEnd: handleDragEnd,
    onDragCancel: handleDragCancel,
  });

  return (
    <DragOverlay
      modifiers={modifiers}
      className={clsx(styles.dragOverlay)}
      dropAnimation={null}
    >
      <div>{componentName}</div>
    </DragOverlay>
  );
};

export default DragEventsHandler;
