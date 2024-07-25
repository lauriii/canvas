import styles from './Preview.module.css';
import type React from 'react';
import { useRef, useEffect, useCallback } from 'react';
import {
  addNewComponentToLayout,
  moveNode,
  selectLayout,
  selectModel,
  sortNode,
} from '@/features/layout/layoutModelSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { Card, Spinner } from '@radix-ui/themes';
import clsx from 'clsx';
import {
  selectDragging,
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
import { DesktopIcon } from '@radix-ui/react-icons';
import useIframeKeyHandlers from '@/hooks/useIframeKeyHandlers';
import useSyncIframeHeightToContent from '@/hooks/useSyncIframeHeightToContent';

interface ViewportProps {
  previewId: string;
  height: number;
  width: number;
  isLoading: boolean;
  frameSrcDoc: string; // HTML as a string to be rendered in the iFrame
}

const Viewport: React.FC<ViewportProps> = (props) => {
  const { height, width, frameSrcDoc, isLoading, previewId } = props;
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const hoveredComponent = useAppSelector(selectHoveredComponent);
  const iframeDocumentRef = useRef<Document | null>(null);
  const { isDragging } = useAppSelector(selectDragging);
  const dispatch = useAppDispatch();
  useIframeKeyHandlers(iframeRef);
  useSyncIframeHeightToContent(iframeRef, height, width);
  const { data: components } = useGetComponentsQuery();
  const componentsRef = useRef(components);

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
      } else {
        // Moving a node from one parent to another
        const receivingParentPath = findNodePathByUuid(
          layout,
          ev.to.dataset.xbUuid,
        );
        if (receivingParentPath) {
          const newPath: number[] = [
            ...receivingParentPath,
            ev.newDraggableIndex,
          ];

          if (ev.clone.dataset.isNew === 'true' && ev.clone.dataset.xbUuid) {
            // @todo ideally we would use the markup of the component here instead of a loading <p>
            if (components) {
              const newNode = Object.values(components).find(
                (c) => c.id === ev.clone.dataset.xbUuid,
              );
              if (newNode) {
                ev.item.innerHTML = newNode['default_markup'];
              }
            }

            dispatch(
              addNewComponentToLayout({
                to: newPath,
                newNode: ev.clone.dataset.xbUuid,
                componentFieldData: componentsRef?.current?.[ev.clone.dataset.xbUuid]?.['field_data'],
              }),
            );
          } else {
            dispatch(moveNode({ uuid: ev.item.dataset.xbUuid, to: newPath }));
          }
        }
      }
    },
    [dispatch, layout, components],
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
    },
    [dispatch, updateData],
  );

  useEffect(() => {
    componentsRef.current = components;
  }, [components]);

  useEffect(() => {
    // Takes each sortable item (component) and adds a dragstart event listener. This is so that we can implement a custom
    // dragImage (the floating representation of what you are dragging that follows your cursor).
    const initSortableListItem = (listItemEl: HTMLElement) => {
      listItemEl.addEventListener('dragstart', (event) => {
        if (iframeDocumentRef.current && listItemEl.dataset.xbUuid) {
          return customSortableDragImage(
            event,
            iframeDocumentRef.current,
            model[listItemEl.dataset.xbUuid].name,
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
        onEnd: handleDragEnd,
      });
    };

    const iframe = iframeRef.current;
    if (!iframe) {
      return;
    }

    // Wait for the iframe to load
    iframe.onload = () => {
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
    };
  }, [
    dispatch,
    height,
    handleDragAdd,
    handleDragEnd,
    handleDragStart,
    layout,
    model,
    components,
  ]);

  return (
    <div>
      <Card mb="2" variant="surface">
        <DesktopIcon />
        {width}px x {height}px
      </Card>
      <div className={styles.previewContainer}>
        <iframe
          ref={iframeRef}
          className={styles.preview}
          data-xb-preview={previewId}
          title="Preview"
          srcDoc={frameSrcDoc}
        ></iframe>
        <div
          className={clsx(styles.loadingOverlay, {
            [styles.show]: isLoading,
          })}
        >
          <Spinner loading={isLoading} size="3" />
        </div>
        {!isDragging && (
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
