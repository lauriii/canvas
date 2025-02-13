import { useEffect, useCallback, useRef } from 'react';
import Sortable from 'sortablejs';
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
  DEFAULT_REGION,
  setTargetSlot,
  unsetTargetSlot,
} from '@/features/ui/uiSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { useGetComponentsQuery } from '@/services/components';
import { useGetSectionsQuery } from '@/services/sections';
import type { RegionsMap, SlotsMap } from '@/types/AnnotationMaps';
import { insertPlaceholderIfMatchingComments } from '@/utils/function-utils';
import { useNavigationUtils } from '@/hooks/useNavigationUtils';
import useXbParams from '@/hooks/useXbParams';

/**
 * This hook initializes the SortableJS implementation to allow for drag and drop interactions within the preview iFrames.
 */

function usePreviewSortable(
  iframe: HTMLIFrameElement | null,
  slotsMap: SlotsMap,
  regionsMap: RegionsMap,
): {
  destroySortables: () => void;
  disableSortables: () => void;
  enableSortables: () => void;
} {
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const dispatch = useAppDispatch();
  const { data: components } = useGetComponentsQuery();
  const { data: sections } = useGetSectionsQuery();
  const modelRef = useRef(model);
  const componentsRef = useRef(components);
  const sectionsRef = useRef(sections);
  const sortableInstancesRef = useRef<Sortable[]>([]);
  const { setSelectedComponent } = useNavigationUtils();
  const { regionId: selectedRegion = DEFAULT_REGION } = useXbParams();

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

      const receivingParentPath = findNodePathByUuid(
        layout,
        ev.to.dataset.xbSlotId || ev.to.dataset.xbUuid, // Slot || region
      );

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
          // If the location we are dropping into was empty and had the empty placeholder div, immediately
          // remove it here so that the newly dropped item doesn't appear alongside it for a split second.
          const wasEmpty = Array.from(ev.to.children).some((child) =>
            child.classList.contains('xb--sortable-slot-empty-placeholder'),
          );
          if (wasEmpty) {
            ev.to.innerHTML = '';
          }

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

            const currentElement =
              componentsRef?.current?.[ev.clone.dataset.xbComponentId];
            if (currentElement !== undefined) {
              dispatch(
                addNewComponentToLayout(
                  {
                    to: newPath,
                    component: currentElement,
                  },
                  setSelectedComponent,
                ),
              );
            }
          } else if (type === 'section') {
            // Adding a section template.
            ev.item.innerHTML = '<p>Loading section...</p>';
            dispatch(
              addNewSectionToLayout(
                {
                  to: newPath,
                  layoutModel:
                    sectionsRef?.current?.[ev.clone.dataset.xbComponentId]
                      .layoutModel,
                },
                setSelectedComponent,
              ),
            );
          }
        }
      }
    },
    [dispatch, layout, setSelectedComponent],
  );

  const handleDragAdd = useCallback(
    (ev: Sortable.SortableEvent) => {
      if ('originalEvent' in ev) {
        // @todo there is a bug here somewhere - drag and drop inside regions sometimes updates the dom but doesn't trigger
        // the preview to re-render on the server size which gets things out of sync.
        const originalEvent: DragEvent = ev.originalEvent as DragEvent;
        // dataTransfer.dropEffect will be 'none' if the dragend event is fired by hitting escape or releasing mouse on
        // an invalid drop area. If it's 'none', remove the dropped item from the DOM and don't call updateData.
        if (originalEvent.dataTransfer?.dropEffect !== 'none') {
          updateData(ev, false);
        } else {
          dispatch(unsetTargetSlot());
          ev.item.remove();
        }
      }
    },
    [dispatch, updateData],
  );

  const handleChange = useCallback(
    (ev: Sortable.SortableEvent) => {
      if (ev.to.dataset.xbSlotId) {
        dispatch(setTargetSlot(ev.to.dataset.xbSlotId));
      } else if (ev.to.dataset.xbRegion) {
        dispatch(setTargetSlot(ev.to.dataset.xbRegion));
      } else {
        dispatch(unsetTargetSlot());
      }
    },
    [dispatch],
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
      // if listEl is empty, insert an empty placeholder div that we can style.
      if (!listEl.children.length) {
        if (listEl.hasAttribute('data-xb-region')) {
          const placeholderDiv = document.createElement('div');
          placeholderDiv.classList.add('xb--sortable-slot-empty-placeholder');
          listEl.appendChild(placeholderDiv);
        }
        insertPlaceholderIfMatchingComments(listEl);
      }
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
        draggable: '[data-xb-uuid]',
        // Keep a clone element in the original position until the drag ends.
        removeCloneOnHide: false,
        onAdd: handleDragAdd,
        onChange: handleChange,
        scrollSensitivity: 120,
        scrollSpeed: 40,
        // Prevent dragging content that's provided as an example (default content) by the SDC.
        filter: '[data-xb-slot-is-empty], .xb--sortable-slot-empty-placeholder',
        emptyInsertThreshold: 50,
      });

      sortableInstancesRef.current.push(sortableInstance);
    };

    const sortableSlots = Object.entries(slotsMap).map(([, slot]) => {
      return slot.element;
    });
    const sortableRegion = regionsMap[selectedRegion]?.element;

    const sortableParentElements = [...sortableSlots];
    if (sortableRegion) {
      sortableParentElements.push(sortableRegion);
    }

    sortableParentElements.forEach((sortableEl) => {
      initSortableList(sortableEl);
    });
  }, [
    iframe?.srcdoc,
    slotsMap,
    regionsMap,
    selectedRegion,
    handleDragAdd,
    handleChange,
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
