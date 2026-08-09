import { useCallback } from 'react';
import clsx from 'clsx';
import { useDraggable } from '@dnd-kit/core';
import { CollapsibleContent } from '@radix-ui/react-collapsible';
import * as Collapsible from '@radix-ui/react-collapsible';
import { TriangleDownIcon, TriangleRightIcon } from '@radix-ui/react-icons';
import { Badge, Box, Flex } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import LayersDropZone from '@/features/layout/layers/LayersDropZone';
import SlotLayer from '@/features/layout/layers/SlotLayer';
import { selectModel } from '@/features/layout/layoutModelSlice';
import {
  DEFAULT_VARIANT_ID,
  getCaseVariantId,
  humanizeVariantId,
  isCaseNode,
  isSwitchNode,
} from '@/features/layout/personalizationUtils';
import ComponentContextMenu, {
  ComponentContextMenuContent,
} from '@/features/layout/preview/ComponentContextMenu';
import {
  selectCollapsedLayers,
  selectComponentIsSelected,
  selectIsComponentHovered,
  setHoveredComponent,
  toggleCollapsedLayer,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import useGetComponentName from '@/hooks/useGetComponentName';
import { useGetSegmentsQuery } from '@/services/personalization';

import type React from 'react';
import type { CollapsibleTriggerProps } from '@radix-ui/react-collapsible';
import type {
  ComponentNode,
  LayoutNode,
} from '@/features/layout/layoutModelSlice';

import styles from './ComponentLayer.module.css';

interface ComponentLayerProps {
  component: ComponentNode;
  children?: false | React.ReactElement<CollapsibleTriggerProps>;
  indent: number;
  parentNode?: LayoutNode;
  index: number;
  disableDrop?: boolean;
}

const ComponentLayer: React.FC<ComponentLayerProps> = ({
  component,
  indent,
  index,
  disableDrop = false,
}) => {
  const dispatch = useAppDispatch();
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, component.uuid);
  });
  const collapsedLayers = useAppSelector(selectCollapsedLayers);
  const { handleComponentSelection } = useComponentSelection();

  const componentId = component.uuid;
  const isCollapsed = collapsedLayers.includes(componentId);
  const defaultName = useGetComponentName(component);
  const isSwitch = isSwitchNode(component);
  const isCase = isCaseNode(component);
  const caseVariantId = useAppSelector((state) =>
    isCase ? getCaseVariantId(selectModel(state), component) : undefined,
  );
  const caseSegments = useAppSelector((state) =>
    isCase ? selectModel(state)[component.uuid]?.resolved?.segments : undefined,
  );
  const { data: segments } = useGetSegmentsQuery(undefined, { skip: !isCase });
  // Case rows are titled by their variant and audience instead of the
  // generic case component name.
  let nodeName = defaultName;
  if (isCase && caseVariantId) {
    const segmentIds = Array.isArray(caseSegments)
      ? (caseSegments as string[])
      : [];
    const audience =
      caseVariantId === DEFAULT_VARIANT_ID
        ? 'Everyone (fallback)'
        : segmentIds
            .map((segmentId) => segments?.[segmentId]?.label ?? segmentId)
            .join(', ');
    nodeName = audience
      ? `${humanizeVariantId(caseVariantId)} — ${audience}`
      : humanizeVariantId(caseVariantId);
  }
  const isSelected = useAppSelector((state) =>
    selectComponentIsSelected(state, componentId),
  );
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
    id: `${component.uuid}_layers`,
    data: {
      origin: 'layers',
      component: component,
      name: nodeName,
    },
  });

  const handleItemClick = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      event.stopPropagation();
      handleComponentSelection(componentId, event.metaKey);
    },
    [handleComponentSelection, componentId],
  );

  const handleItemMouseEnter = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      event.stopPropagation();
      if (!isDragging) {
        dispatch(setHoveredComponent(componentId));
      }
    },
    [dispatch, componentId, isDragging],
  );

  const handleItemMouseLeave = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      event.stopPropagation();
      dispatch(unsetHoveredComponent());
    },
    [dispatch],
  );

  const handleItemDragStart = useCallback(
    (event: React.DragEvent<HTMLDivElement>) => {
      event.stopPropagation();
      dispatch(unsetHoveredComponent());
    },
    [dispatch],
  );

  const handleContextMenu = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      event.preventDefault();
      event.stopPropagation();
    },
    [],
  );

  const handleOpenChange = () => {
    dispatch(toggleCollapsedLayer(componentId));
  };

  return (
    <Box
      {...listeners}
      {...attributes}
      ref={setNodeRef}
      role="treeitem"
      aria-roledescription="Draggable component"
      data-canvas-uuid={componentId}
      data-canvas-type={component.nodeType}
      data-canvas-selected={isSelected}
      onClick={handleItemClick}
      onDragStart={handleItemDragStart}
      onContextMenu={handleContextMenu}
      aria-labelledby={`layer-${componentId}-name`}
      position="relative"
    >
      <ComponentContextMenu component={component}>
        <Collapsible.Root
          className="canvas--collapsible-root"
          open={!isCollapsed}
          onOpenChange={handleOpenChange}
          data-canvas-uuid={component.uuid}
        >
          <SidebarNode
            id={`layer-${componentId}-name`}
            onMouseEnter={handleItemMouseEnter}
            onMouseLeave={handleItemMouseLeave}
            className="canvas-drag-handle"
            title={nodeName}
            draggable={true}
            variant="component"
            hovered={isHovered}
            selected={isSelected}
            disabled={disableDrop || isDragging}
            open={component.slots.length ? !isCollapsed : false}
            trailingContent={
              isSwitch ? (
                <Badge size="1" color="gray" aria-label="Personalized section">
                  Personalized
                </Badge>
              ) : undefined
            }
            dropdownMenuContent={
              <ComponentContextMenuContent
                component={component}
                menuType="dropdown"
              />
            }
            indent={indent}
            leadingContent={
              <Flex>
                <Box width="var(--space-4)" mr="1">
                  {component.slots.length > 0 ? (
                    <Collapsible.Trigger
                      asChild={true}
                      onClick={(e) => {
                        e.stopPropagation();
                      }}
                    >
                      <button
                        aria-label={
                          isCollapsed
                            ? `Expand component tree`
                            : `Collapse component tree`
                        }
                      >
                        {isCollapsed ? (
                          <TriangleRightIcon />
                        ) : (
                          <TriangleDownIcon />
                        )}
                      </button>
                    </Collapsible.Trigger>
                  ) : (
                    <Box />
                  )}
                </Box>
              </Flex>
            }
          />
          {component.slots.length > 0 && (
            <CollapsibleContent
              className={clsx({
                [styles.componentChildrenSelected]: isSelected,
                [styles.componentChildrenDisabled]: disableDrop || isDragging,
              })}
            >
              {component.slots.map((slot) => (
                <SlotLayer
                  key={slot.id}
                  slot={slot}
                  indent={indent + 1}
                  parentNode={component}
                  disableDrop={disableDrop || isDragging}
                />
              ))}
            </CollapsibleContent>
          )}
        </Collapsible.Root>
      </ComponentContextMenu>
      {!isDragging && !disableDrop && (
        <>
          {index === 0 && (
            <LayersDropZone
              layer={component}
              position={'top'}
              indent={indent}
            />
          )}
          <LayersDropZone
            layer={component}
            position={'bottom'}
            indent={indent}
          />
        </>
      )}
    </Box>
  );
};

export default ComponentLayer;
