import { useEffect, useCallback, useRef } from 'react';
import Sortable from 'sortablejs';
import {
  customSortableDragImage,
  isDropTargetInSlotAllowedByEdgeDistance,
} from '@/features/sortable/sortableUtils';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  addNewComponentToLayout,
  addNewSectionToLayout,
  moveNode,
  selectLayout,
  selectModel,
  sortNode,
} from '@/features/layout/layoutModelSlice';
import { setPreviewDragging } from '@/features/ui/uiSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { useGetComponentsQuery } from '@/services/components';
import { useGetSectionsQuery } from '@/services/sections';

/**
 * This hook initializes the SortableJS implementation to allow for drag and drop interactions within the preview iFrames.
 */

function usePreviewSortable(iframe: HTMLIFrameElement | null) {
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const dispatch = useAppDispatch();
  const { data: components } = useGetComponentsQuery();
  const { data: sections } = useGetSectionsQuery();
  const modelRef = useRef(model);
  const iframeDocumentRef = useRef<Document | null>(null);
  const componentsRef = useRef(components);
  const sectionsRef = useRef(sections);
  const sortableInstanceRef = useRef<Sortable>();

  // Takes each sortable item (component) and adds a dragstart event listener. This is so that we can implement a custom
  // dragImage (the floating representation of what you are dragging that follows your cursor).
  const initSortableListItem = (listItemEl: HTMLElement) => {
    listItemEl.addEventListener('dragstart', (event) => {
      if (iframeDocumentRef.current && event.target) {
        const target = event.target as HTMLElement;
        if (!target.dataset.xbUuid) {
          return;
        }
        return customSortableDragImage(
          event,
          iframeDocumentRef.current,
          modelRef.current[target.dataset.xbUuid].name,
        );
      }
    });
  };

  const updateData = useCallback(
    (ev: Sortable.SortableEvent, sort: boolean) => {
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
                newNode: ev.clone.dataset.xbComponentId,
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
                newSection: ev.clone.dataset.xbComponentId,
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

  const handleDragClone = useCallback((ev: Sortable.SortableEvent) => {
    // Add a class to the clone element so that we can style it. This is the element that shows up in the original position
    // when dragging.
    ev.clone.classList.add('xb--sortable-clone');
    // SortableJS sets `display: none` as an inline style. We could override that with `!important` in CSS since we
    // already have a class on the clone element, but that causes an error with an internal function of SortableJS.
    ev.clone.style.display = 'block';
  }, []);

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

  const handleChange = useCallback((ev: Sortable.SortableEvent) => {
    iframeDocumentRef.current
      ?.querySelectorAll('.xb--sortable-slot-hover')
      .forEach((el) => {
        el.classList.remove('xb--sortable-slot-hover');
      });
    ev.to
      .closest('[data-xb-component-id="slot"]')
      ?.classList.add('xb--sortable-slot-hover');
  }, []);

  const handleDragEnd = useCallback(
    (ev: Sortable.SortableEvent) => {
      dispatch(setPreviewDragging(false));
      iframeDocumentRef.current?.body.classList.remove('xb--preview-dragging');

      // Normally handle the data update in dragAdd unless the item is being dragged within the same container, in which
      // case dragAdd doesn't fire, so we can call it from here.
      if (ev.to === ev.from) {
        updateData(ev, true);
      }

      iframeDocumentRef.current
        ?.querySelectorAll('.xb--sortable-slot-hover')
        .forEach((el) => {
          el.classList.remove('xb--sortable-slot-hover');
        });
    },
    [dispatch, updateData],
  );

  const init = useCallback(() => {
    if (!iframe?.srcdoc) {
      return;
    }

    const initSortableList = (listEl: HTMLElement) => {
      // Initialize SortableJS on the elements inside the iframe
      sortableInstanceRef.current = Sortable.create(listEl, {
        animation: 0,
        invertSwap: true,
        swapThreshold: 0.5,
        ghostClass: 'xb--sortable-ghost',
        group: {
          name: 'layout',
          pull: true,
          put: ['layout', 'list'],
          revertClone: false,
        },
        dataIdAttr: 'data-xb-uuid',
        // Keep a clone element in the original position until the drag ends.
        removeCloneOnHide: false,
        onClone: handleDragClone,
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
      const draggableItems =
        sortableList.querySelectorAll<HTMLElement>('.xb--sortable-item');

      initSortableList(sortableList);
      draggableItems.forEach((item) => {
        initSortableListItem(item);
      });
    });
  }, [
    iframe,
    handleDragClone,
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
    if (iframe) {
      init();
    }
    return () => {
      if (sortableInstanceRef.current) {
        sortableInstanceRef.current.destroy();
      }
    };
  }, [iframe, init]);
}

export default usePreviewSortable;
