import type React from 'react';
import { useEffect, useRef, useState } from 'react';
import styles from './PreviewOverlay.module.css';
import useSyncElementSize from '@/hooks/useSyncElementSize';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectCanvasViewPortScale,
  selectDragging,
  setPreviewDragging,
  selectHoveredComponent,
  selectSelectedComponent,
  setHoveredComponent,
  setSelectedComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import clsx from 'clsx';
import NameTag from '@/features/layout/preview/NameTag';
import AddButton from '@/features/layout/preview/AddButton';
import Sortable from 'sortablejs';
import SlotOverlay from '@/features/layout/previewOverlay/SlotOverlay';
import {
  customSortableDragImage,
  getSortableGroupName,
  isDropTargetInSlotAllowedByEdgeDistance,
} from '@/features/sortable/sortableUtils';
import type { LayoutNode } from '@/features/layout/layoutModelSlice';
import ComponentContextMenu from '@/features/layout/preview/ComponentContextMenu';
import { getDistanceBetweenElements } from '@/utils/function-utils';
import useGetComponentName from '@/hooks/useGetComponentName';

export interface ComponentOverlayProps {
  component: any;
  iframeRef: React.RefObject<HTMLIFrameElement>;
  parentComponent: any;
}

function moveElement(
  element: HTMLElement,
  newParent: HTMLElement,
  newIndex: number,
): void {
  if (!element || !newParent) {
    console.error('Element or new parent does not exist.');
    return;
  }

  const currentParent = element.parentNode as HTMLElement;

  // Remove the element from its current parent if it has one
  if (currentParent) {
    currentParent.removeChild(element);
  }

  const children = Array.from(newParent.children);

  // Ensure the new index is within valid range
  if (newIndex < 0 || newIndex > children.length) {
    console.error('New index is out of bounds.');
    return;
  }

  // Insert the element at the new position
  if (newIndex === children.length) {
    newParent.appendChild(element);
  } else {
    newParent.insertBefore(element, children[newIndex]);
  }
}

const ComponentOverlay: React.FC<ComponentOverlayProps> = (props) => {
  const { component, parentComponent, iframeRef } = props;
  const rect = useSyncElementSize(iframeRef.current, component.uuid);
  const [elementOffset, setElementOffset] = useState({
    horizontalDistance: 0,
    verticalDistance: 0,
  });
  const [initialized, setInitialized] = useState(false);
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const hoveredComponent = useAppSelector(selectHoveredComponent);
  const canvasViewPortScale = useAppSelector(selectCanvasViewPortScale);
  const dispatch = useAppDispatch();
  const nameTagElRef = useRef<HTMLDivElement | null>(null);
  const { isDragging } = useAppSelector(selectDragging);
  const sortableContainerRef = useRef<HTMLDivElement | null>(null);
  const sortableInstance = useRef<Sortable | null>(null);
  const elementInsideIframe = useRef<HTMLElement | null>(null);
  const name = useGetComponentName(component);

  useEffect(() => {
    const iframeDocument = iframeRef.current?.contentDocument;
    if (!iframeDocument) {
      return;
    }

    // Find the element inside the iframe - :not data-xb-overlay because that is the ghost element
    // that has been dropped from the ComponentOverlay when moving components.
    elementInsideIframe.current = iframeDocument.querySelector(
      `[data-xb-uuid="${component.uuid}"]:not([data-xb-overlay="true"])`,
    );
    const parentElementInsideIframe = iframeDocument.querySelector(
      `[data-xb-uuid="${parentComponent.uuid}"]`,
    );

    if (parentElementInsideIframe && elementInsideIframe.current) {
      setElementOffset(
        getDistanceBetweenElements(
          parentElementInsideIframe,
          elementInsideIframe.current,
        ),
      );
      // Only set this to true once the offset has been correctly calculated to avoid the border flickering to the top
      // left when the preview updates.
      setInitialized(true);
    }
  }, [component.uuid, iframeRef, parentComponent.uuid, rect]);

  function handleComponentClick(event: React.MouseEvent<HTMLElement>) {
    event.stopPropagation();
    dispatch(setSelectedComponent(component.uuid));
  }

  function handleItemMouseOver(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(setHoveredComponent(component.uuid));
  }

  function handleItemMouseOut(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(unsetHoveredComponent());
  }

  function handleKeyDown(event: React.KeyboardEvent<HTMLDivElement>) {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault(); // Prevents scrolling when space is pressed
      event.stopPropagation(); // Prevents key firing on a parent component
      dispatch(setSelectedComponent(component.uuid));
    }
  }

  useEffect(() => {
    if (sortableContainerRef?.current) {
      sortableInstance.current = Sortable.create(
        sortableContainerRef.current as HTMLDivElement,
        {
          sort: false,
          dataIdAttr: 'data-xb-uuid',
          animation: 0,
          ghostClass: 'xb--sortable-ghost',
          draggable: '.xb--sortable-item',
          onMove: (
            ev: Sortable.MoveEvent,
            originalEvent: Event | { clientX: number; clientY: number },
          ) => {
            return isDropTargetInSlotAllowedByEdgeDistance(ev, originalEvent);
          },
          onStart: () => {
            dispatch(setPreviewDragging(true));
            // Set opacity on the real dragged element and make it not draggable so it doesn't interfere with the indexing.
            elementInsideIframe.current?.classList.add('xb--sortable-clone');
            elementInsideIframe.current?.setAttribute('draggable', 'false');
          },
          onEnd: (e) => {
            // Optimistically move the DOM element inside the iFrame to the new location so it updates immediately
            // even if the new doc takes a while to come back from the back end.
            if (
              elementInsideIframe.current &&
              getSortableGroupName(e.to) === 'layout'
            ) {
              if (e.to !== undefined && e.newIndex !== undefined) {
                moveElement(elementInsideIframe.current, e.to, e.newIndex);
              }
            }
            elementInsideIframe.current?.classList.remove('xb--sortable-clone');
            elementInsideIframe.current?.removeAttribute('draggable');

            // When dragging, the original item is dragged off into the canvas, and SortableJS puts a clone in its place.
            // After dragging we swap the original item back so that React doesn't lose its reference to it.
            if (e.clone.parentNode && e.clone.parentNode !== e.to) {
              e.clone.parentNode.replaceChild(e.item, e.clone);
            }
            dispatch(setPreviewDragging(false));
          },
          group: {
            name: 'list',
            pull: 'clone',
            put: false,
            revertClone: true,
          },
        },
      );
    }

    return () => {
      if (sortableInstance.current instanceof Sortable) {
        sortableInstance.current.destroy();
      }
    };
  }, [dispatch]);

  return (
    <ComponentContextMenu component={component}>
      <div
        aria-labelledby={`${component.uuid}-name`}
        tabIndex={0}
        onMouseOver={handleItemMouseOver}
        onMouseOut={handleItemMouseOut}
        onClick={handleComponentClick}
        onKeyDown={handleKeyDown}
        data-xb-selected={component.uuid === selectedComponent}
        className={clsx('componentOverlay', styles.componentOverlay, {
          [styles.selected]: component.uuid === selectedComponent,
          [styles.hovered]: component.uuid === hoveredComponent,
          [styles.dragging]: isDragging,
        })}
        style={{
          display: initialized ? '' : 'none',
          height: rect.height * canvasViewPortScale,
          width: rect.width * canvasViewPortScale,
          top: elementOffset.verticalDistance * canvasViewPortScale,
          left: elementOffset.horizontalDistance * canvasViewPortScale,
        }}
        ref={sortableContainerRef}
      >
        <div
          className={clsx('xb--sortable-item', styles.sortableItem)}
          data-xb-component-id={component.type}
          data-xb-uuid={component.uuid}
          data-xb-type={component.nodeType}
          onDragStart={(event) => {
            event.stopPropagation();
            customSortableDragImage(event, window.document, name);
          }}
          data-xb-overlay="true"
        >
          {component.children.map((slot: LayoutNode) => (
            <SlotOverlay
              key={slot.uuid}
              iframeRef={iframeRef}
              parentComponent={component}
              slot={slot}
            />
          ))}
          <div
            className={clsx(
              'xb--component-controls',
              styles.xbComponentControls,
            )}
          >
            <button className="visually-hidden" onClick={handleComponentClick}>
              Select component
            </button>
            <div ref={nameTagElRef} className={clsx(styles.xbNameTag)}>
              <NameTag
                name={name}
                componentUuid={component.uuid}
                selected={selectedComponent === component.uuid}
                nodeType={component.nodeType}
              />
            </div>
            {selectedComponent === component.uuid && (
              <div className={clsx(styles.xbAddSectionButton)}>
                <AddButton elementId={component.uuid} />
              </div>
            )}
          </div>
        </div>
      </div>
    </ComponentContextMenu>
  );
};

export default ComponentOverlay;
