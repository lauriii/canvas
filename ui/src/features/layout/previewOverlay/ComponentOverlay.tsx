import type React from 'react';
import { useEffect, useRef, useState } from 'react';
import styles from './PreviewOverlay.module.css';
import useSyncPreviewElementSize from '@/hooks/useSyncPreviewElementSize';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectCanvasViewPortScale,
  selectDragging,
  setPreviewDragging,
  selectHoveredComponent,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import clsx from 'clsx';
import NameTag from '@/features/layout/preview/NameTag';
import Sortable from 'sortablejs';
import SlotOverlay from '@/features/layout/previewOverlay/SlotOverlay';
import {
  customSortableDragImage,
  getSortableGroupName,
  isDropTargetBetweenTwoElementsOfSameComponent,
  isDropTargetInSlotAllowedByEdgeDistance,
} from '@/features/sortable/sortableUtils';
import type {
  ComponentNode,
  RegionNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import ComponentContextMenu from '@/features/layout/preview/ComponentContextMenu';
import { getDistanceBetweenElements } from '@/utils/function-utils';
import useGetComponentName from '@/hooks/useGetComponentName';
import { useDataToHtmlMapValue } from '@/features/layout/preview/DataToHtmlMapContext';
import { useNavigationUtils } from '@/hooks/useNavigationUtils';
import useXbParams from '@/hooks/useXbParams';

export interface ComponentOverlayProps {
  component: ComponentNode;
  iframeRef: React.RefObject<HTMLIFrameElement>;
  parentSlot?: SlotNode;
  parentRegion?: RegionNode;
}

// Moves 1 or more DOM elements to a new parent DOM element.
function moveDomElements(
  elements: HTMLElement | HTMLElement[],
  newParent: HTMLElement,
  newIndex: number,
): void {
  if (!elements || !newParent) {
    console.error('Elements or new parent does not exist.');
    return;
  }

  // Ensure elements is always treated as an array
  const elementArray = Array.isArray(elements) ? elements : [elements];

  // Validate all elements
  for (const element of elementArray) {
    if (!element.parentNode) {
      console.error('One of the elements does not have a parent.');
      return;
    }
  }

  // Ensure the new index is within a valid range
  const children = Array.from(newParent.children);
  if (newIndex < 0 || newIndex > children.length) {
    console.error('New index is out of bounds.');
    return;
  }

  // Move each element to the new parent
  elementArray.forEach((element, i) => {
    const currentParent = element.parentNode as HTMLElement;

    // Remove the element from its current parent
    if (currentParent) {
      currentParent.removeChild(element);
    }

    // Calculate the adjusted index for each subsequent element
    const adjustedIndex = Math.min(newIndex + i, children.length);

    // Insert the element at the new position
    if (adjustedIndex === children.length) {
      newParent.appendChild(element);
    } else {
      newParent.insertBefore(element, children[adjustedIndex]);
    }
  });
}

const ComponentOverlay: React.FC<ComponentOverlayProps> = (props) => {
  const { component, parentSlot, parentRegion, iframeRef } = props;
  const { componentsMap, slotsMap, regionsMap } = useDataToHtmlMapValue();
  const rect = useSyncPreviewElementSize(
    componentsMap[component.uuid]?.elements,
  );
  const [elementOffset, setElementOffset] = useState({
    horizontalDistance: 0,
    verticalDistance: 0,
  });
  const [initialized, setInitialized] = useState(false);
  const { componentId: selectedComponent } = useXbParams();
  const hoveredComponent = useAppSelector(selectHoveredComponent);
  const canvasViewPortScale = useAppSelector(selectCanvasViewPortScale);
  const dispatch = useAppDispatch();
  const { setSelectedComponent } = useNavigationUtils();
  const nameTagElRef = useRef<HTMLDivElement | null>(null);
  const { isDragging } = useAppSelector(selectDragging);
  const sortableContainerRef = useRef<HTMLDivElement | null>(null);
  const sortableInstance = useRef<Sortable | null>(null);
  const elementsInsideIframe = useRef<HTMLElement[] | []>([]);
  const name = useGetComponentName(component);

  useEffect(() => {
    const iframeDocument = iframeRef.current?.contentDocument;
    if (!iframeDocument || !componentsMap[component.uuid]) {
      return;
    }

    elementsInsideIframe.current = componentsMap[component.uuid]?.elements;

    let parentElementInsideIframe = null;
    if (parentRegion?.id) {
      parentElementInsideIframe = regionsMap[parentRegion.id]?.element;
    }
    if (parentSlot?.id) {
      parentElementInsideIframe = slotsMap[parentSlot.id].element;
    }

    if (parentElementInsideIframe && elementsInsideIframe.current.length) {
      setElementOffset(
        getDistanceBetweenElements(
          parentElementInsideIframe,
          // @todo Potential bug: an element amongst the elementsInsideIframe array other than the first could be further to the top/left than the first element.
          elementsInsideIframe.current[0],
        ),
      );
      // Only set this to true once the offset has been correctly calculated to avoid the border flickering to the top
      // left when the preview updates.
      setInitialized(true);
    }
  }, [
    slotsMap,
    componentsMap,
    rect,
    component.uuid,
    iframeRef,
    parentSlot?.id,
    parentRegion?.id,
    regionsMap,
  ]);

  function handleComponentClick(event: React.MouseEvent<HTMLElement>) {
    event.stopPropagation();
    setSelectedComponent(component.uuid);
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
      setSelectedComponent(component.uuid);
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
          draggable: '[data-xb-uuid]',
          onMove: (
            ev: Sortable.MoveEvent,
            originalEvent: Event | { clientX: number; clientY: number },
          ) => {
            if (isDropTargetBetweenTwoElementsOfSameComponent(ev)) {
              return false;
            }

            return isDropTargetInSlotAllowedByEdgeDistance(ev, originalEvent);
          },
          onStart: () => {
            dispatch(setPreviewDragging(true));
            // Set opacity on the real dragged element and make it not draggable so it doesn't interfere with the indexing.
            elementsInsideIframe.current?.forEach((el) => {
              el.classList.add('xb--sortable-clone');
              el.setAttribute('draggable', 'false');
            });
          },
          onEnd: (e) => {
            // Optimistically move the DOM element inside the iFrame to the new location so it updates immediately
            // even if the new doc takes a while to come back from the back end.
            if (
              elementsInsideIframe.current &&
              getSortableGroupName(e.to) === 'layout'
            ) {
              if (e.to !== undefined && e.newIndex !== undefined) {
                moveDomElements(elementsInsideIframe.current, e.to, e.newIndex);
              }
            }
            elementsInsideIframe.current?.forEach((el) => {
              el.classList.remove('xb--sortable-clone');
              el.removeAttribute('draggable');
            });

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
          {component.slots.map((slot: SlotNode) => (
            <SlotOverlay
              key={slot.name}
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
          </div>
        </div>
      </div>
    </ComponentContextMenu>
  );
};

export default ComponentOverlay;
