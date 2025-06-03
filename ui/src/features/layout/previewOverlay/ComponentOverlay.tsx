import type React from 'react';
import { useMemo } from 'react';
import { useEffect, useRef, useState } from 'react';
import styles from './PreviewOverlay.module.css';
import useSyncPreviewElementSize from '@/hooks/useSyncPreviewElementSize';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectCanvasViewPortScale,
  selectComponentIsSelected,
  selectDragging,
  selectIsComponentHovered,
  selectIsComponentUpdating,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import clsx from 'clsx';
import { ComponentNameTag } from '@/features/layout/preview/NameTag';
import SlotOverlay from '@/features/layout/previewOverlay/SlotOverlay';
import type {
  ComponentNode,
  RegionNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import ComponentContextMenu from '@/features/layout/preview/ComponentContextMenu';
import { getDistanceBetweenElements } from '@/utils/function-utils';
import useGetComponentName from '@/hooks/useGetComponentName';
import { useDataToHtmlMapValue } from '@/features/layout/preview/DataToHtmlMapContext';
import useComponentSelection from '@/hooks/useComponentSelection';
import ComponentDropZone from '@/features/layout/previewOverlay/ComponentDropZone';
import { useDraggable } from '@dnd-kit/core';
import type { StackDirection } from '@/types/Annotations';

export interface ComponentOverlayProps {
  component: ComponentNode;
  iframeRef: React.RefObject<HTMLIFrameElement>;
  parentSlot?: SlotNode;
  parentRegion?: RegionNode;
  index: number;
  disableDrop?: boolean;
}

type ElementOffset = {
  horizontalDistance: number | undefined;
  verticalDistance: number | undefined;
};

const ComponentOverlay: React.FC<ComponentOverlayProps> = (props) => {
  const {
    component,
    parentSlot,
    parentRegion,
    iframeRef,
    index,
    disableDrop = false,
  } = props;

  const { componentsMap, slotsMap, regionsMap } = useDataToHtmlMapValue();
  const rect = useSyncPreviewElementSize(
    componentsMap[component.uuid]?.elements,
  );
  const [elementOffset, setElementOffset] = useState<ElementOffset>({
    horizontalDistance: undefined,
    verticalDistance: undefined,
  });
  const [initialized, setInitialized] = useState(false);
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, component.uuid);
  });
  const isUpdating = useAppSelector((state) => {
    return selectIsComponentUpdating(state, component.uuid);
  });
  const canvasViewPortScale = useAppSelector(selectCanvasViewPortScale);
  const dispatch = useAppDispatch();
  const { setSelectedComponent, handleComponentSelection } =
    useComponentSelection();
  const { isDragging } = useAppSelector(selectDragging);
  const elementsInsideIframe = useRef<HTMLElement[] | []>([]);
  const name = useGetComponentName(component);
  const {
    attributes,
    listeners,
    setNodeRef,
    isDragging: isComponentDragged,
  } = useDraggable({
    id: `${component.uuid}`,
    data: {
      origin: 'overlay',
      component: component,
      name: name,
      elementsInsideIframe: elementsInsideIframe.current,
    },
  });

  const isSelected = useAppSelector((state) =>
    selectComponentIsSelected(state, component.uuid),
  );

  useEffect(() => {
    const iframeDocument = iframeRef.current?.contentDocument;
    if (!iframeDocument || !componentsMap[component.uuid]) {
      return;
    }

    elementsInsideIframe.current = componentsMap[component.uuid]?.elements;

    let parentElementInsideIframe = null;
    if (parentRegion?.id) {
      parentElementInsideIframe = regionsMap[parentRegion.id]?.elements[0];
    }
    if (parentSlot?.id) {
      parentElementInsideIframe = slotsMap[parentSlot.id].element;
    }

    if (parentElementInsideIframe && elementsInsideIframe.current.length) {
      setElementOffset((prevOffsets) => {
        const newOffsets = {
          ...getDistanceBetweenElements(
            parentElementInsideIframe,
            elementsInsideIframe.current,
          ),
        };

        if (
          prevOffsets.horizontalDistance !== newOffsets.horizontalDistance ||
          prevOffsets.verticalDistance !== newOffsets.verticalDistance
        ) {
          return newOffsets;
        }
        return prevOffsets;
      });
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

  useEffect(() => {
    if (
      elementOffset.horizontalDistance !== undefined ||
      elementOffset.verticalDistance !== undefined
    ) {
      // Only set this to true once the offset has been correctly calculated to avoid the border flickering to the top
      // left when the preview updates.
      setInitialized(true);
    }
  }, [elementOffset.horizontalDistance, elementOffset.verticalDistance]);

  function handleComponentClick(event: React.MouseEvent<HTMLElement>) {
    event.stopPropagation();
    handleComponentSelection(component.uuid, event.metaKey);
  }

  function handleItemMouseOver(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    if (!isDragging) {
      dispatch(setHoveredComponent(component.uuid));
    }
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

  const style: React.CSSProperties = useMemo(
    () => ({
      opacity: initialized ? '1' : '0',
      height: rect.height * canvasViewPortScale,
      width: rect.width * canvasViewPortScale,
      top: (elementOffset.verticalDistance || 0) * canvasViewPortScale,
      left: (elementOffset.horizontalDistance || 0) * canvasViewPortScale,
    }),
    [initialized, rect.height, rect.width, canvasViewPortScale, elementOffset],
  );

  let stackDirection: StackDirection = 'vertical';
  if (parentSlot && slotsMap) {
    stackDirection = slotsMap[parentSlot.id]?.stackDirection || 'vertical';
  }

  const [componentType] = component.type.split('@');

  return (
    <div
      aria-label={`${name}`}
      tabIndex={0}
      onMouseOver={handleItemMouseOver}
      onMouseOut={handleItemMouseOut}
      onClick={handleComponentClick}
      onKeyDown={handleKeyDown}
      data-xb-selected={isSelected}
      className={clsx('componentOverlay', styles.componentOverlay, {
        [styles.selected]: isSelected,
        [styles.hovered]: isHovered,
        [styles.dragging]: isComponentDragged,
        [styles.updating]: isUpdating,
      })}
      style={style}
    >
      <button className="visually-hidden" onClick={handleComponentClick}>
        Select component
      </button>

      <ComponentContextMenu component={component}>
        <div
          aria-label={`Draggable component ${name}`}
          ref={setNodeRef}
          {...listeners}
          {...attributes}
          className={clsx('xb--sortable-item', styles.sortableItem)}
          data-xb-component-id={componentType}
          data-xb-uuid={component.uuid}
          data-xb-type={component.nodeType}
          data-xb-overlay="true"
        />
      </ComponentContextMenu>
      {(isHovered || isSelected) && (
        <div className={clsx(styles.xbNameTag)}>
          <ComponentNameTag
            name={name}
            id={component.uuid}
            nodeType={component.nodeType}
          />
        </div>
      )}
      {component.slots.map((slot: SlotNode) => (
        <SlotOverlay
          key={slot.name}
          iframeRef={iframeRef}
          parentComponent={component}
          slot={slot}
          disableDrop={disableDrop || isComponentDragged}
        />
      ))}

      {!isComponentDragged && !disableDrop && !isUpdating && (
        <>
          {index === 0 && (
            <ComponentDropZone
              component={component}
              position={stackDirection.startsWith('v') ? 'top' : 'left'}
              parentSlot={parentSlot}
              parentRegion={parentRegion}
            />
          )}
          <ComponentDropZone
            component={component}
            position={stackDirection.startsWith('v') ? 'bottom' : 'right'}
            parentSlot={parentSlot}
            parentRegion={parentRegion}
          />
        </>
      )}
    </div>
  );
};

export default ComponentOverlay;
