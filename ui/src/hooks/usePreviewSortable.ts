import { useEffect, useCallback, useRef } from 'react';
import Sortable from 'sortablejs';
import { isDropTargetInSlotAllowedByEdgeDistance } from '@/features/sortable/sortableUtils';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  addNewComponentToLayout,
  addNewSectionToLayout,
  moveNode,
  selectLayout,
  selectModel,
  sortNode,
} from '@/features/layout/layoutModelSlice';
import {
  setPreviewDragging,
  setTargetSlot,
  unsetTargetSlot,
} from '@/features/ui/uiSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { useGetComponentsQuery } from '@/services/components';
import { useGetDummySectionsQuery } from '@/services/sections';

/**
 * This hook initializes the SortableJS implementation to allow for drag and drop interactions within the preview iFrames.
 */

function usePreviewSortable(iframe: HTMLIFrameElement | null): {
  destroySortables: () => void;
  disableSortables: () => void;
  enableSortables: () => void;
} {
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const dispatch = useAppDispatch();
  const { data: components } = useGetComponentsQuery();
  // TODO update to use the real section query once it works.
  const { data: sections } = useGetDummySectionsQuery();
  const modelRef = useRef(model);
  const iframeDocumentRef = useRef<Document | null>(null);
  const componentsRef = useRef(components);
  const sectionsRef = useRef(sections);
  const sortableInstancesRef = useRef<Sortable[]>([]);

  const updateData = useCallback(
    (ev: Sortable.SortableEvent, sort: boolean) => {
      dispatch(unsetTargetSlot());
      if (typeof ev.newDraggableIndex !== 'number') {
        return;
      }
      if (sort) {
        // Moving a node within the same parent.
        dispatch(
          sortNode({ uuid: ev.item.dataset.xbUuid, to: ev.newDraggableIndex }),
        );
        return;
      }

      const receivingParentPath =
        ev.to.dataset.xbRoot === 'true'
          ? []
          : findNodePathByUuid(layout, ev.to.dataset.xbUuid);
      if (receivingParentPath) {
        const newPath: number[] = [
          ...receivingParentPath,
          ev.newDraggableIndex,
        ];

        if (ev.clone.dataset.isNew !== 'true') {
          // Moving an existing node from one parent to another.
          dispatch(moveNode({ uuid: ev.item.dataset.xbUuid, to: newPath }));
          return;
        }

        const type = ev.clone.dataset.xbType;

        if (ev.clone.dataset.xbComponentId) {
          if (type === 'component') {
            // Adding a component.
            if (componentsRef.current) {
              const newNode = Object.values(componentsRef.current).find(
                (c) => c.id === ev.clone.dataset.xbComponentId,
              );
              if (newNode) {
                ev.item.innerHTML = newNode['default_markup'];
              }
            }

            dispatch(
              addNewComponentToLayout({
                to: newPath,
                component:
                  componentsRef?.current?.[ev.clone.dataset.xbComponentId],
              }),
            );
          } else if (type === 'section') {
            // Adding a section template.
            ev.item.innerHTML = '<p>Loading section...</p>';
            dispatch(
              addNewSectionToLayout({
                to: newPath,
                layoutModel:
                  sectionsRef?.current?.[ev.clone.dataset.xbComponentId]
                    .layoutModel,
              }),
            );
          }
        }
      }
    },
    [dispatch, layout],
  );

  const handleDragAdd = useCallback(
    (ev: Sortable.SortableEvent) => {
      updateData(ev, false);
    },
    [updateData],
  );

  const handleDragStart = useCallback(() => {
    dispatch(setPreviewDragging(true));
    iframeDocumentRef.current?.body.classList.add('xb--preview-dragging');
  }, [dispatch]);

  const handleDragMove = useCallback(
    (
      ev: Sortable.MoveEvent,
      originalEvent: Event | { clientX: number; clientY: number },
    ) => {
      let isTargetAllowed = true;

      // Prevent placing a component by dragging too close to the top or bottom edge of a slot.
      if (!isDropTargetInSlotAllowedByEdgeDistance(ev, originalEvent)) {
        isTargetAllowed = false;
      }

      // Prevent placing a component below its own clone element (the element that stays in the original place when
      // dragging) if it's the only one in the list (slot or root layout).
      if (
        ev.related.classList.contains('xb--sortable-clone') &&
        ev.related.parentElement?.querySelectorAll(
          '.xb--sortable-item:not(.xb--sortable-ghost)',
        ).length === 1 &&
        ev.willInsertAfter
      ) {
        isTargetAllowed = false;
      }

      return isTargetAllowed;
    },
    [],
  );

  const handleChange = useCallback(
    (ev: Sortable.SortableEvent) => {
      if (ev.to.dataset.xbUuid) {
        dispatch(setTargetSlot(ev.to.dataset.xbUuid));
      } else {
        dispatch(unsetTargetSlot());
      }
    },
    [dispatch],
  );

  const handleDragEnd = useCallback(
    (ev: Sortable.SortableEvent) => {
      dispatch(setPreviewDragging(false));
      iframeDocumentRef.current?.body.classList.remove('xb--preview-dragging');

      // Normally handle the data update in dragAdd unless the item is being dragged within the same container, in which
      // case dragAdd doesn't fire, so we can call it from here.
      if (ev.to === ev.from) {
        updateData(ev, true);
      }
    },
    [dispatch, updateData],
  );

  const destroySortables = useCallback(() => {
    if (iframe && sortableInstancesRef.current.length) {
      sortableInstancesRef.current.forEach((instance) => {
        instance.destroy();
      });
    }
  }, [iframe]);

  const disableSortables = useCallback(() => {
    if (iframe && sortableInstancesRef.current.length) {
      sortableInstancesRef.current.forEach((instance) => {
        instance.option('disabled', true);
      });
    }
  }, [iframe]);

  const enableSortables = useCallback(() => {
    if (iframe && sortableInstancesRef.current.length) {
      sortableInstancesRef.current.forEach((instance) => {
        instance.option('disabled', false);
      });
    }
  }, [iframe]);

  const init = useCallback(() => {
    if (!iframe?.srcdoc) {
      return;
    }

    sortableInstancesRef.current = [];

    const initSortableList = (listEl: HTMLElement) => {
      // Initialize SortableJS on the elements inside the iframe
      const sortableInstance = Sortable.create(listEl, {
        animation: 0,
        invertSwap: true,
        swapThreshold: 0.5,
        ghostClass: 'xb--sortable-ghost',
        group: {
          name: 'layout',
          pull: false,
          put: ['list'],
          revertClone: false,
        },
        dataIdAttr: 'data-xb-uuid',
        // Keep a clone element in the original position until the drag ends.
        removeCloneOnHide: false,
        onAdd: handleDragAdd,
        onStart: handleDragStart,
        onMove: handleDragMove,
        onChange: handleChange,
        onEnd: handleDragEnd,
        scrollSensitivity: 120,
        scrollSpeed: 40,
        // Prevent dragging content that's provided as an example (default content) by the SDC.
        filter: '[data-xb-slot-is-empty]',
        emptyInsertThreshold: 50,
      });

      sortableInstancesRef.current.push(sortableInstance);
    };

    iframeDocumentRef.current = iframe.contentDocument;

    if (!iframeDocumentRef.current) {
      return;
    }
    const sortableLists =
      iframeDocumentRef.current.querySelectorAll<HTMLElement>(
        '.xb--sortable-list',
      );

    sortableLists.forEach((sortableList) => {
      initSortableList(sortableList);
    });
  }, [
    iframe,
    handleDragAdd,
    handleDragStart,
    handleDragMove,
    handleChange,
    handleDragEnd,
  ]);

  useEffect(() => {
    modelRef.current = model;
  }, [model]);

  useEffect(() => {
    componentsRef.current = components;
  }, [components]);

  useEffect(() => {
    sectionsRef.current = sections;
  }, [sections]);

  useEffect(() => {
    if (iframe) {
      init();
    }

    return () => {
      destroySortables();
    };
  }, [destroySortables, iframe, init]);

  return { destroySortables, enableSortables, disableSortables };
}

export default usePreviewSortable;
