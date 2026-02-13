import { useEffect, useState } from 'react';
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
  const [isDraggingFolder, setIsDraggingFolder] = useState(false);
  const [isFolderAtBoundary, setIsFolderAtBoundary] = useState(false);
  const { handleFolderDrop } = useDropOnFolderHandler();
  const { handleNewDrop } = useDropFromLibraryHandler();
  const { handleExistingDrop } = useDropFromLayoutHandler();

  // Apply/remove folderAtBoundary class when state changes.
  useEffect(() => {
    if (isFolderAtBoundary) {
      window.document.body.classList.add(styles.folderAtBoundary);
    } else {
      window.document.body.classList.remove(styles.folderAtBoundary);
    }

    // Cleanup: remove class when component unmounts.
    return () => {
      window.document.body.classList.remove(styles.folderAtBoundary);
    };
  }, [isFolderAtBoundary]);

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

  // Custom modifier to restrict folder dragging to sidebar area.
  const restrictFolderToSidebar = ({ transform, active }: any) => {
    if (active?.data?.current?.type !== 'folder') {
      return transform;
    }

    // Get the sidebar element using the existing test ID.
    const sidebar = document.querySelector(
      '[data-testid="canvas-primary-panel"]',
    );
    if (!sidebar) {
      return transform;
    }

    const sidebarRect = sidebar.getBoundingClientRect();
    const activeRect = active.rect.current.translated;

    if (!activeRect) {
      return transform;
    }

    // Check if dragging beyond the right edge of sidebar.
    if (activeRect.left + transform.x > sidebarRect.right) {
      // Constrain to sidebar right boundary and change cursor to default.
      if (!isFolderAtBoundary) {
        setIsFolderAtBoundary(true);
      }
      return {
        ...transform,
        x: sidebarRect.right - activeRect.left,
      };
    }

    // Reset cursor to grabbing if within bounds.
    if (isFolderAtBoundary) {
      setIsFolderAtBoundary(false);
    }
    return transform;
  };

  const modifiers = ['layers', 'code'].includes(dragOrigin)
    ? [snapRightToCursor, restrictToFirstScrollableAncestor]
    : isDraggingFolder
      ? [restrictFolderToSidebar]
      : [snapRightToCursor, restrictToWindowEdges];

  function handleDragStart(event: DragStartEvent) {
    initMouseTracking();
    setComponentName(event.active.data?.current?.name);
    const isFolderDrag = event.active.data?.current?.type === 'folder';
    setIsDraggingFolder(isFolderDrag);
    setIsFolderAtBoundary(false);
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
    const { over, active } = event;
    const parentSlot = over?.data?.current?.parentSlot;
    const parentRegion = over?.data?.current?.parentRegion;

    // If dragging a folder and hovering over non-folder destination, prevent visual feedback.
    if (active.data?.current?.type === 'folder') {
      if (!over || over.data?.current?.destination !== 'folder') {
        dispatch(unsetTargetSlot());
        return;
      }
    }

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
    setIsDraggingFolder(false);
    setIsFolderAtBoundary(false);
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
      ['folder', 'uncategorized'].includes(
        over.data?.current?.destination || '',
      ) &&
      ['library', 'code', 'folder'].includes(origin)
    ) {
      // Handle drop into folder from library or folder reordering.
      handleFolderDrop(event);
    } else if (
      origin === 'overlay' ||
      over.data.current?.destination === 'layers'
    ) {
      // Handle dropping an existing instance back into layout from overlay or layers panel
      handleExistingDrop(event, afterDrag);
    } else if (
      origin === 'library' &&
      active.data?.current?.type !== 'folder'
    ) {
      // Handle dropping components/patterns from library (folders excluded).
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
      {!isDraggingFolder && <div>{componentName}</div>}
    </DragOverlay>
  );
};

export default DragEventsHandler;
