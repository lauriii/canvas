import { useMemo } from 'react';
import clsx from 'clsx';
import { useDraggable } from '@dnd-kit/core';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ComponentContextMenu from '@/features/layout/preview/ComponentContextMenu';
import { ComponentTag } from '@/features/layout/preview/ComponentTag';
import ComponentToolbar from '@/features/layout/preview/ComponentToolbar';
import { usePreviewDom } from '@/features/layout/preview/PreviewDomContext';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import ComponentDropZone from '@/features/layout/previewOverlay/ComponentDropZone';
import SlotOverlay from '@/features/layout/previewOverlay/SlotOverlay';
import {
  selectComponentIsSelected,
  selectDragging,
  selectEditorViewPortScale,
  selectIsComponentHovered,
  selectIsComponentUpdating,
  selectSelectedComponentUuid,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import useGetComponentName from '@/hooks/useGetComponentName';
import { isMarkerComponentType } from '@/services/pageVariants';

import type React from 'react';
import type { CanvasStackDirection } from '@drupal-canvas/preview-geometry';
import type {
  ComponentNode,
  RegionNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import styles from './PreviewOverlay.module.css';

export interface ComponentOverlayProps {
  component: ComponentNode;
  // The component the slot the component is in belongs to.
  parentComponent?: ComponentNode;
  parentSlot?: SlotNode;
  parentRegion?: RegionNode;
  index: number;
  disableDrop?: boolean;
}

const ComponentOverlay: React.FC<ComponentOverlayProps> = (props) => {
  const {
    component,
    parentComponent,
    parentSlot,
    parentRegion,
    index,
    disableDrop = false,
  } = props;

  const componentsMap = usePreviewDom()?.componentsMap;
  const { geometryMap } = usePreviewGeometry();
  const componentGeometry = geometryMap.component[component.uuid];
  const parentGeometry = parentRegion?.id
    ? geometryMap.region[parentRegion.id]
    : parentSlot?.id
      ? geometryMap.slot[parentSlot.id]
      : undefined;
  const offsetLeft =
    componentGeometry && parentGeometry
      ? componentGeometry.rect.left - parentGeometry.rect.left
      : 0;
  const offsetTop =
    componentGeometry && parentGeometry
      ? componentGeometry.rect.top - parentGeometry.rect.top
      : 0;
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, component.uuid);
  });
  const isUpdating = useAppSelector((state) => {
    return selectIsComponentUpdating(state, component.uuid);
  });
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const dispatch = useAppDispatch();
  const { setSelectedComponent, handleComponentSelection } =
    useComponentSelection();
  const { isDragging } = useAppSelector(selectDragging);
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
      elementsInsideIframe: componentsMap?.[component.uuid]?.elements ?? [],
    },
  });

  const isSelected = useAppSelector((state) =>
    selectComponentIsSelected(state, component.uuid),
  );
  // The toolbar is for a single selected component.
  const hasToolbar =
    useAppSelector(
      (state) => selectSelectedComponentUuid(state) === component.uuid,
    ) && !isDragging;

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
    // The pointer is still over the component when it moves onto the name of
    // the component. Removing the name then would put the pointer over the
    // component again, which shows the name again, and so on.
    if (event.currentTarget.contains(event.relatedTarget as Node | null)) {
      return;
    }
    dispatch(unsetHoveredComponent());
  }

  function handleKeyDown(event: React.KeyboardEvent<HTMLDivElement>) {
    // Keys pressed in the toolbar are for its buttons.
    if (event.target !== event.currentTarget) {
      return;
    }
    if (event.code === 'Enter' || (event.code === 'Space' && !event.repeat)) {
      event.preventDefault(); // Prevents scrolling when space is pressed
      event.stopPropagation(); // Prevents key firing on a parent component
      setSelectedComponent(component.uuid);
    }
  }

  const style: React.CSSProperties = useMemo(
    () => ({
      height: (componentGeometry?.rect.height ?? 0) * editorViewPortScale,
      width: (componentGeometry?.rect.width ?? 0) * editorViewPortScale,
      top: offsetTop * editorViewPortScale,
      left: offsetLeft * editorViewPortScale,
    }),
    [
      componentGeometry?.rect.height,
      componentGeometry?.rect.width,
      editorViewPortScale,
      offsetLeft,
      offsetTop,
    ],
  );

  let stackDirection: CanvasStackDirection = 'vertical';
  if (parentSlot) {
    stackDirection =
      geometryMap.slot[parentSlot.id]?.stackDirection || 'vertical';
  }

  const [componentType] = component.type.split('@');
  // Components at the top of the page leave no room above them for their
  // name and toolbar.
  const inside = (componentGeometry?.rect.top ?? 0) * editorViewPortScale < 30;

  if (!componentGeometry || !parentGeometry) {
    return null;
  }

  return (
    <div
      aria-label={`${name}`}
      tabIndex={0}
      onMouseOver={handleItemMouseOver}
      onMouseOut={handleItemMouseOut}
      onClick={handleComponentClick}
      onKeyDown={handleKeyDown}
      data-canvas-selected={isSelected}
      data-canvas-drop-item={component.uuid}
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
          className={clsx('canvas--sortable-item', styles.sortableItem)}
          data-canvas-component-id={componentType}
          data-canvas-uuid={component.uuid}
          data-canvas-type={component.nodeType}
          data-canvas-overlay="true"
        />
      </ComponentContextMenu>
      {hasToolbar && (
        <ComponentToolbar
          component={component}
          name={name}
          parentUuid={parentComponent?.uuid}
          index={index}
          siblingCount={
            (parentSlot ?? parentRegion)?.components.length ?? index + 1
          }
          horizontal={stackDirection.startsWith('h')}
          inside={inside}
          dragListeners={listeners}
        />
      )}
      {!hasToolbar && isHovered && !isDragging && (
        <ComponentTag
          name={name}
          id={component.uuid}
          isMarker={isMarkerComponentType(component.type)}
          inside={inside}
          dragListeners={listeners}
        />
      )}
      {component.slots.map((slot: SlotNode) => (
        <SlotOverlay
          key={slot.name}
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
            />
          )}
          <ComponentDropZone
            component={component}
            position={stackDirection.startsWith('v') ? 'bottom' : 'right'}
            parentSlot={parentSlot}
          />
        </>
      )}
    </div>
  );
};

export default ComponentOverlay;
