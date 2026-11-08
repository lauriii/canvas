import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { DragOverlay, useDndMonitor } from '@dnd-kit/core';
import { restrictToFirstScrollableAncestor } from '@dnd-kit/modifiers';
import { getEventCoordinates } from '@dnd-kit/utilities';

import { useAppDispatch, useAppStore } from '@/app/hooks';
import { NodeType, selectLayout } from '@/features/layout/layoutModelSlice';
import { findParent } from '@/features/layout/layoutUtils';
import {
  endLivePreview,
  isLivePreviewActive,
  isLivePreviewCopyShown,
  showLivePreviewAt,
  startInsertPreview,
  startMovePreview,
} from '@/features/layout/preview/livePreview';
import {
  addPreviewCss,
  getPreviewDocument,
} from '@/features/layout/preview/optimisticPreview';
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

import { freezeDropTargets, unfreezeDropTargets } from './previewDropTarget';
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
  const store = useAppStore();
  const [componentName, setComponentName] = useState('...');
  const [dragOrigin, setDragOrigin] = useState('');
  const [isDraggingFolder, setIsDraggingFolder] = useState(false);
  const [isFolderAtBoundary, setIsFolderAtBoundary] = useState(false);
  // Whether a copy of the dragged component follows the pointer in the preview.
  const [isCopyShown, setIsCopyShown] = useState(false);
  const { handleFolderDrop } = useDropOnFolderHandler();
  const { handleNewDrop } = useDropFromLibraryHandler();
  const { handleExistingDrop } = useDropFromLayoutHandler();
  // The slot or region the dragged component is in when the drag starts.
  const homeContainer = useRef<string>();

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
    const viewport = document
      .querySelector('.canvas--viewport-overlay [data-canvas-drop-container]')
      ?.closest('.canvas--viewport-overlay');
    if (viewport) {
      freezeDropTargets(viewport);
    }
    startLivePreview(event, origin);
    if (homeContainer.current) {
      dispatch(setTargetSlot(homeContainer.current));
    }
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
        homeContainer.current = parent.id;
        startMovePreview(
          previewDocument,
          uuid,
          {
            type: parent.nodeType === NodeType.Region ? 'region' : 'slot',
            id: parent.id,
          },
          getEventCoordinates(event.activatorEvent!) ?? undefined,
        );
      }
    } else if (origin === 'library' && data.type !== 'folder') {
      addPreviewCss(previewDocument, data.item?.css ?? '');
      startInsertPreview(
        previewDocument,
        data.item?.default_markup,
        data.name,
        getEventCoordinates(event.activatorEvent!) ?? undefined,
      );
    }
  }

  function handleDragOver(event: DragOverEvent) {
    const { over, active } = event;
    if (isLivePreviewActive()) {
      showLivePreviewAt(over ? String(over.id) : null);
    }
    // Drop zones in the preview have no destination.
    setIsCopyShown(
      isLivePreviewCopyShown() && !!over && !over.data.current?.destination,
    );
    // Without a drop target, the dragged component is shown where it was.
    const targetSlot =
      over?.data?.current?.parentSlot?.id ??
      (!over && isLivePreviewActive() ? homeContainer.current : undefined);

    // If dragging a folder and hovering over non-folder destination, prevent visual feedback.
    if (active.data?.current?.type === 'folder') {
      if (!over || over.data?.current?.destination !== 'folder') {
        dispatch(unsetTargetSlot());
        return;
      }
    }

    if (targetSlot) {
      dispatch(setTargetSlot(targetSlot));
    } else {
      dispatch(unsetTargetSlot());
    }
  }

  function dragEndCancelCommon() {
    unfreezeDropTargets();
    homeContainer.current = undefined;
    window.document.body.classList.remove('canvas--live-drop-preview');
    dispatch(setPreviewDragging(false));
    dispatch(setListDragging(false));
    dispatch(setTreeDragging(false));
    dispatch(setCodeDragging(false));
    dispatch(unsetTargetSlot());
    setIsDraggingFolder(false);
    setIsFolderAtBoundary(false);
    setIsCopyShown(false);
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
      {/* A copy of a component dragged in the preview follows the pointer. */}
      {!isDraggingFolder && dragOrigin !== 'overlay' && !isCopyShown && (
        <div>{componentName}</div>
      )}
    </DragOverlay>
  );
};

export default DragEventsHandler;
