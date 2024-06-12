import styles from './Preview.module.css';
import { useRef, useEffect, useState } from 'react';
import Sortable from 'sortablejs';
import Outline from './Outline';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectDragging,
  selectSelectedComponent,
  selectHoveredComponent,
  setPreviewDragging,
  setSelectedComponent,
  setHoveredComponent,
} from '@/features/ui/uiSlice';
import {
  moveNode,
  selectLayout,
  sortNode,
  addNewComponentToLayout,
  selectModel,
} from '@/features/layout/layoutModelSlice';
import clsx from 'clsx';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { usePostPreviewMutation } from '@/services/preview';
import { Spinner } from '@radix-ui/themes';
import { customSortableDragImage } from '@/features/sortable/sortableUtils';

interface PreviewProps {
  height: number;
  width: number;
}

const modifierKey = 'Space';

const Preview: React.FC<PreviewProps> = (props) => {
  const { height, width } = props;
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const layout = useAppSelector(selectLayout);
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const hoveredComponent = useAppSelector(selectHoveredComponent);
  const iframeDocumentRef = useRef<Document | null>(null);
  const { isDragging } = useAppSelector(selectDragging);
  const model = useAppSelector(selectModel);
  const dispatch = useAppDispatch();
  const [hoveredElementId, setHoveredElementId] = useState<
    string | undefined
  >();
  const [frameSrcDoc, setFrameSrcDoc] = useState('');
  const [postPreview, { data, isLoading, error }] = usePostPreviewMutation();

  const bindEvents = () => {};

  function handleDragStart(ev: Sortable.SortableEvent) {
    dispatch(setPreviewDragging(true));
    iframeDocumentRef.current?.body.classList.add('preview-dragging');
  }

  function handleDragAdd(ev: Sortable.SortableEvent) {
    updateData(ev, false);
  }

  function handleDragEnd(ev: Sortable.SortableEvent) {
    dispatch(setPreviewDragging(false));
    iframeDocumentRef.current?.body.classList.remove('preview-dragging');

    // Normally handle the data update in dragAdd unless the item is being dragged within the same container, in which
    // case dragAdd doesn't fire, so we can call it from here.
    if (ev.to === ev.from) {
      updateData(ev, true);
    }
  }

  function updateData(ev: Sortable.SortableEvent, sort: boolean) {
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
          ev.item.innerHTML = '<div class="lds-hourglass"></div>';
          dispatch(
            addNewComponentToLayout({
              to: newPath,
              newNode: {
                uuid: 'tempUUID',
                children: [],
                type: 'component',
                componentType: ev.clone.dataset.xbUuid,
                name: ev.clone.dataset.xbName,
              },
            }),
          );
        } else {
          dispatch(moveNode({ uuid: ev.item.dataset.xbUuid, to: newPath }));
        }
      }
    }
  }

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
        dispatch(setHoveredComponent(target.dataset.xbUuid));
      }
    });
  };

  const initComponentClick = (listItemEl: HTMLElement) => {
    listItemEl.addEventListener('click', function (event: MouseEvent) {
      event.stopPropagation();
      if (event.target) {
        const target = event.currentTarget as HTMLElement;
        // setHoveredElementId(target.dataset.xbUuid);
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

  useEffect(() => {
    const iframe = iframeRef.current;
    function notifyParentDocument(event: KeyboardEvent) {
      if (event.type === 'keydown') {
        if (
          (event.metaKey && event.key === 'z' && !event.shiftKey) ||
          (event.ctrlKey && event.key === 'z')
        ) {
          window.parent.postMessage('dispatchUndo', '*');
          return;
        }
        if (
          (event.metaKey && event.shiftKey && event.key === 'z') ||
          (event.ctrlKey && event.key === 'y')
        ) {
          window.parent.postMessage('dispatchRedo', '*');
          return;
        }
        if (event.code === 'NumpadAdd' || event.code === 'Equal') {
          window.parent.postMessage('dispatchZoomIn', '*');
          return;
        }
        if (event.code === 'NumpadSubtract' || event.code === 'Minus') {
          window.parent.postMessage('dispatchZoomOut', '*');
          return;
        }
        if (event.code === modifierKey) {
          window.parent.postMessage('dispatchModifierKeyDown', '*');
          return;
        }
      }
      if (event.type === 'keyup') {
        if (event.code === modifierKey) {
          window.parent.postMessage('dispatchModifierKeyUp', '*');
          return;
        }
      }
    }
    if (iframe) {
      console.log('layout or model changed', model);
      // Wait for the iframe to load
      iframe.onload = () => {
        console.log('On load fired');
        iframeDocumentRef.current = iframe.contentDocument;
        const sortableLists = iframeDocumentRef.current?.getElementsByClassName(
          'sortable-list',
        ) as HTMLCollectionOf<HTMLElement>;

        Array.from(sortableLists).forEach((sortableList) => {
          const draggableItems = sortableList.getElementsByClassName(
            'sortable-item',
          ) as HTMLCollectionOf<HTMLElement>;
          initSortableList(sortableList);
          Array.from(draggableItems).forEach((item) => {
            initSortableListItem(item);
            initComponentHover(item);
            initComponentClick(item);
          });
        });

        // Add an event listener to the iFrame that listens to hot keys.
        iframeDocumentRef.current?.body.addEventListener(
          'keydown',
          notifyParentDocument,
        );
        iframeDocumentRef.current?.body.addEventListener(
          'keyup',
          notifyParentDocument,
        );
        return () => {
          iframeDocumentRef.current?.body.removeEventListener(
            'keydown',
            notifyParentDocument,
          );
          iframeDocumentRef.current?.body.removeEventListener(
            'keyup',
            notifyParentDocument,
          );
        };
      };
    }
  }, [layout, model]);

  useEffect(() => {
    const sendPreviewRequest = async () => {
      try {
        // Trigger the mutation
        const result = await postPreview({ layout, model }).unwrap();
        // Handle the successful response here
        console.log(result); // Do something with the result
        setFrameSrcDoc(result.html);
      } catch (err) {
        // Handle the error here
        console.error(err); // Do something with the error
      }
    };
    if (layout && model) {
      sendPreviewRequest();
    }
  }, [layout, model]);

  return (
    <div className={styles.previewContainer}>
      <iframe
        ref={iframeRef}
        className={styles.preview}
        id="preview"
        srcDoc={frameSrcDoc}
        style={{ height: `${height}px`, width: `${width}px` }}
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
            elementId={hoveredComponent}
            iframeRef={iframeRef}
            // setHoveredElementId={setHoveredElementId}
            selected={false}
          />
          <Outline
            elementId={selectedComponent}
            iframeRef={iframeRef}
            // setHoveredElementId={setHoveredElementId}
            selected={true}
          />
        </>
      )}
    </div>
  );
};
export default Preview;
