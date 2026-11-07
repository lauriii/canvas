import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { DragOverlay, useDndMonitor } from '@dnd-kit/core';
import { restrictToFirstScrollableAncestor } from '@dnd-kit/modifiers';

import { useAppDispatch, useAppStore } from '@/app/hooks';
import { NodeType, selectLayout } from '@/features/layout/layoutModelSlice';
import { findParent } from '@/features/layout/layoutUtils';
import {
  endLivePreview,
  isLivePreviewActive,
  LIVE_PREVIEW_SETTLE_MS,
  showLivePreviewAt,
  startInsertPreview,
  startMovePreview,
} from '@/features/layout/preview/livePreview';
import { getPreviewDocument } from '@/features/layout/preview/optimisticPreview';
import {
  setCodeDragging,
  setListDragging,
  setPreviewDragging,
  setTargetSlot,
  setTreeDragging,
  setUpdatingComponent,
  unsetHoveredComponent,
  unsetTargetSlot,
} from '@/features/ui/uiSlice';
import { useDropFromLayoutHandler } from '@/hooks/useDropFromLayoutHandler';
import { useDropFromLibraryHandler } from '@/hooks/useDropFromLibraryHandler';
import { useDropOnFolderHandler } from '@/hooks/useDropOnFolderHandler';

import {
  cleanupMouseTracking,
  getTrackedPointerPosition,
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
  const store = useAppStore();
  const [componentName, setComponentName] = useState('...');
  const [dragOrigin, setDragOrigin] = useState('');
  const [isDraggingFolder, setIsDraggingFolder] = useState(false);
  const [isFolderAtBoundary, setIsFolderAtBoundary] = useState(false);
  const { handleFolderDrop } = useDropOnFolderHandler();
  const { handleNewDrop } = useDropFromLibraryHandler();
  const { handleExistingDrop } = useDropFromLayoutHandler();
  const recheck = useRef<{
    timer?: ReturnType<typeof setTimeout>;
    pointer?: string;
    count: number;
  }>({ count: 0 });

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
      : // The drag overlay is as large as the dragged component, so clamping
        // it to the window would pin the label of a tall component to a
        // corner instead of the cursor.
        [snapRightToCursor];

  function handleDragStart(event: DragStartEvent) {
    initMouseTracking();
    // Nothing is hovered while dragging. The overlay only clears its hovered
    // component on mouseout, which never fires when a drag starts without the
    // pointer leaving that component.
    dispatch(unsetHoveredComponent());
    setComponentName(event.active.data?.current?.name);
    const isFolderDrag = event.active.data?.current?.type === 'folder';
    setIsDraggingFolder(isFolderDrag);
    setIsFolderAtBoundary(false);
    window.document.body.classList.add(styles.dragging);
    const origin = getOrigin(event);
    setDragOrigin(origin);
    startLivePreview(event, origin);
    // The dragged component itself shows where it will be dropped.
    window.document.body.classList.toggle(
      'canvas--live-drop-preview',
      isLivePreviewActive(),
    );
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

  // Shows the dragged component at its drop position while it is dragged.
  function startLivePreview(
    event: DragStartEvent,
    origin: ReturnType<typeof getOrigin>,
  ) {
    const previewDocument = getPreviewDocument();
    const data = event.active.data.current;
    if (!previewDocument || !data) {
      return;
    }
    if (origin === 'overlay') {
      const uuid = String(event.active.id);
      const parent = findParent(selectLayout(store.getState()), uuid);
      if (parent && parent.nodeType !== NodeType.Component) {
        startMovePreview(previewDocument, uuid, {
          type: parent.nodeType === NodeType.Region ? 'region' : 'slot',
          id: parent.id,
        });
      }
    } else if (origin === 'library' && data.type !== 'folder') {
      startInsertPreview(previewDocument, data.item?.default_markup, data.name);
    }
  }

  // The drop target is kept while the overlay catches up with the page
  // reflowed by the live preview (see App.tsx), and drop targets are only
  // resolved when the pointer moves. Resolve it again once the overlay has
  // caught up, in case the pointer has stopped moving by then.
  function scheduleRecheck() {
    const pointer = getTrackedPointerPosition();
    const state = recheck.current;
    clearTimeout(state.timer);
    if (!pointer) {
      return;
    }
    // Guards against the target changing back and forth without the pointer
    // moving.
    const key = `${pointer.x},${pointer.y}`;
    state.count = state.pointer === key ? state.count + 1 : 0;
    state.pointer = key;
    if (state.count > 3) {
      return;
    }
    state.timer = setTimeout(() => {
      window.document.dispatchEvent(
        new PointerEvent('pointermove', {
          clientX: pointer.x,
          clientY: pointer.y,
          bubbles: true,
        }),
      );
    }, LIVE_PREVIEW_SETTLE_MS + 20);
  }

  function handleDragOver(event: DragOverEvent) {
    const { over, active } = event;
    if (isLivePreviewActive()) {
      showLivePreviewAt(over ? String(over.id) : null);
      scheduleRecheck();
    }
    const parentSlot = over?.data?.current?.parentSlot;

    // If dragging a folder and hovering over non-folder destination, prevent visual feedback.
    if (active.data?.current?.type === 'folder') {
      if (!over || over.data?.current?.destination !== 'folder') {
        dispatch(unsetTargetSlot());
        return;
      }
    }

    if (parentSlot) {
      dispatch(setTargetSlot(parentSlot.id));
    } else {
      dispatch(unsetTargetSlot());
    }
  }

  function dragEndCancelCommon() {
    clearTimeout(recheck.current.timer);
    recheck.current = { count: 0 };
    window.document.body.classList.remove('canvas--live-drop-preview');
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
    try {
      handleDrop(event);
    } finally {
      // Puts the dragged component back where it was, unless the drop
      // handlers kept it where the live preview shows it.
      endLivePreview();
    }
  }

  function handleDrop(event: DragEndEvent) {
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
    endLivePreview();
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
