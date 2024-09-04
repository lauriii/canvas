import styles from './Preview.module.css';
import type React from 'react';
import { useRef, useEffect, useCallback, useState } from 'react';
import {
  addNewComponentToLayout,
  addNewSectionToLayout,
  moveNode,
  selectLayout,
  selectModel,
  sortNode,
} from '@/features/layout/layoutModelSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { Progress } from '@radix-ui/themes';
import {
  selectDragging,
  selectPanning,
  selectHoveredComponent,
  selectSelectedComponent,
  setHoveredComponent,
  setPreviewDragging,
  setSelectedComponent,
} from '@/features/ui/uiSlice';
import Outline from '@/features/layout/preview/Outline';
import Sortable from 'sortablejs';
import { customSortableDragImage } from '@/features/sortable/sortableUtils';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { useGetComponentsQuery } from '@/services/components';
import { useGetSectionsQuery } from '@/services/sections';
import useIframeKeyHandlers from '@/hooks/useIframeKeyHandlers';
import useSyncIframeHeightToContent from '@/hooks/useSyncIframeHeightToContent';
import ViewportToolbar from '@/features/layout/preview/ViewportToolbar';

export interface ViewportProps {
  size: 'lg' | 'sm';
  name: string;
  height: number;
  width: number;
  isLoading: boolean;
  frameSrcDoc: string; // HTML as a string to be rendered in the iFrame
}

const Viewport: React.FC<ViewportProps> = (props) => {
  const { name, height, width, frameSrcDoc, isLoading, size } = props;
  const [isReloading, setIsReloading] = useState(false);
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const hoveredComponent = useAppSelector(selectHoveredComponent);
  const iframeDocumentRef = useRef<Document | null>(null);
  const { isDragging } = useAppSelector(selectDragging);
  const { isPanning } = useAppSelector(selectPanning);
  const dispatch = useAppDispatch();
  useIframeKeyHandlers(iframeRef);
  useSyncIframeHeightToContent(iframeRef, height, width);
  const { data: components } = useGetComponentsQuery();
  const { data: sections } = useGetSectionsQuery();
  const componentsRef = useRef(components);
  const sectionsRef = useRef(sections);

  const handleDragStart = useCallback(() => {
    dispatch(setPreviewDragging(true));
    iframeDocumentRef.current?.body.classList.add('preview-dragging');
  }, [dispatch]);

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

  const toggleEmptySlotExampleVisibility = useCallback(
    (el: HTMLElement, show: boolean) => {
      const elements = el.querySelectorAll(
        // Empty slots are annotated with a `data-xb-slot-is-empty` attribute.
        // Inside the slot we target all children (the example content), but
        // exclude the ghost element that's created when dragging.
        '[data-xb-slot-is-empty] > *:not(.sortable-ghost)',
      );
      elements.forEach((child) => {
        // Simply set the display property as an inline style.
        (child as HTMLElement).style.display = show ? '' : 'none';
      });
    },
    [],
  );

  const handleDragMove = useCallback(
    (ev: Sortable.MoveEvent) => {
      // When a component/section is dragged over an empty slot, we want to hide
      // the example content that's provided by the SDC.
      // As the first step, display previously hidden empty slot examples. This is
      // relevant when there are multiple empty slots available and a component is
      // being dragged over them. We only want to hide the example in the current
      // drop target.
      iframeDocumentRef.current?.body &&
        toggleEmptySlotExampleVisibility(iframeDocumentRef.current?.body, true);
      // Now hide the example in the slot that's currently targeted for drop.
      ev.to && toggleEmptySlotExampleVisibility(ev.to, false);
    },
    [toggleEmptySlotExampleVisibility],
  );

  const handleDragAdd = useCallback(
    (ev: Sortable.SortableEvent) => {
      updateData(ev, false);
    },
    [updateData],
  );

  const handleDragEnd = useCallback(
    (ev: Sortable.SortableEvent) => {
      dispatch(setPreviewDragging(false));
      iframeDocumentRef.current?.body.classList.remove('preview-dragging');

      // Normally handle the data update in dragAdd unless the item is being dragged within the same container, in which
      // case dragAdd doesn't fire, so we can call it from here.
      if (ev.to === ev.from) {
        updateData(ev, true);
      }

      // Restore the visibility of previously hidden empty slot examples.
      // See `handleDragMove` for more details.
      iframeDocumentRef.current?.body &&
        toggleEmptySlotExampleVisibility(iframeDocumentRef.current?.body, true);
    },
    [dispatch, updateData, toggleEmptySlotExampleVisibility],
  );

  useEffect(() => {
    componentsRef.current = components;
  }, [components]);

  useEffect(() => {
    sectionsRef.current = sections;
  }, [sections]);

  useEffect(() => {
    if (iframeRef.current) {
      setIsReloading(true);
      iframeRef.current.srcdoc = frameSrcDoc;
    }
  }, [frameSrcDoc]);

  useEffect(() => {
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
            model[target.dataset.xbUuid].name,
          );
        }
      });
    };
    const initComponentHover = (listItemEl: HTMLElement) => {
      listItemEl.addEventListener('mouseover', function (event: MouseEvent) {
        event.stopPropagation();
        if (event.target) {
          const target = event.currentTarget as HTMLElement;
          if (!target.dataset.xbUuid) {
            return;
          }
          dispatch(setHoveredComponent(target.dataset.xbUuid));
        }
      });
    };

    const initComponentClick = (listItemEl: HTMLElement) => {
      listItemEl.addEventListener('click', function (event: MouseEvent) {
        // In safari middle mouse click fires the click event with button === 1
        if (event.button === 1) {
          return;
        }
        event.stopPropagation();
        if (event.target) {
          const target = event.currentTarget as HTMLElement;
          if (!target.dataset.xbUuid) {
            return;
          }
          dispatch(setSelectedComponent(target.dataset.xbUuid));
        }
      });
    };

    const initSortableList = (listEl: HTMLElement) => {
      // Initialize SortableJS on the elements inside the iframe
      Sortable.create(listEl, {
        animation: 0,
        invertSwap: true,
        group: {
          name: 'layout',
          pull: true,
          put: ['layout', 'list'],
          revertClone: false,
        },
        dataIdAttr: 'data-xb-uuid',
        onAdd: handleDragAdd,
        onStart: handleDragStart,
        onMove: handleDragMove,
        onEnd: handleDragEnd,
        // Prevent dragging content that's provided as an example (default content) by the SDC.
        filter: '[data-xb-slot-is-empty]',
      });
    };

    const iframe = iframeRef.current;
    if (!iframe) {
      return;
    }
    iframe.dataset.testXbContentInitialized = 'false';
    // Wait for the iframe to load
    iframe.onload = () => {
      if (!iframe.srcdoc) {
        return;
      }
      iframeDocumentRef.current = iframe.contentDocument;

      if (!iframeDocumentRef.current) {
        return;
      }
      const sortableLists =
        iframeDocumentRef.current.querySelectorAll<HTMLElement>(
          '.sortable-list',
        );

      sortableLists.forEach((sortableList) => {
        const draggableItems =
          sortableList.querySelectorAll<HTMLElement>('.sortable-item');

        initSortableList(sortableList);
        draggableItems.forEach((item) => {
          initSortableListItem(item);
          initComponentHover(item);
          initComponentClick(item);
        });
      });

      setIsReloading(false);
      iframe.dataset.testXbContentInitialized = 'true';
    };
  }, [
    dispatch,
    height,
    handleDragAdd,
    handleDragEnd,
    handleDragStart,
    handleDragMove,
    layout,
    model,
  ]);

  return (
    <div>
      <ViewportToolbar size={size} name={name} width={width} height={height} />
      <div className={styles.previewContainer}>
        {(isLoading || isReloading) && (
          <>
            <Progress
              aria-label="Loading Preview"
              className={styles.progress}
              duration="1s"
            />
          </>
        )}
        <iframe
          ref={iframeRef}
          className={styles.preview}
          data-xb-preview={size}
          data-test-xb-content-initialized="false"
          title="Preview"
        ></iframe>
        {!isDragging && !isPanning && (
          <>
            <Outline
              elementId={selectedComponent}
              iframeRef={iframeRef}
              selected={true}
            />
            {selectedComponent !== hoveredComponent && (
              <Outline
                elementId={hoveredComponent}
                iframeRef={iframeRef}
                selected={false}
              />
            )}
          </>
        )}
      </div>
    </div>
  );
};

export default Viewport;
