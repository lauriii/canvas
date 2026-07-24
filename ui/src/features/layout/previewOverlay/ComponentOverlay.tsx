import { useMemo } from 'react';
import clsx from 'clsx';
import { useDraggable } from '@dnd-kit/core';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ComponentContextMenu from '@/features/layout/preview/ComponentContextMenu';
import { ComponentNameTag } from '@/features/layout/preview/NameTag';
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
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import useGetComponentName from '@/hooks/useGetComponentName';

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
  parentSlot?: SlotNode;
  parentRegion?: RegionNode;
  index: number;
  disableDrop?: boolean;
}

const ComponentOverlay: React.FC<ComponentOverlayProps> = (props) => {
  const {
    component,
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
      {(isHovered || isSelected) && (
        <div className={clsx(styles.canvasNameTag)}>
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
