import type React from 'react';
import { useState } from 'react';
import { Box, Flex } from '@radix-ui/themes';
import { TriangleDownIcon, TriangleRightIcon } from '@radix-ui/react-icons';
import SidebarNode from '@/components/sidebar/SidebarNode';
import { customSortableDragImage } from '@/features/sortable/sortableUtils';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectHoveredComponent,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import type {
  ComponentNode,
  LayoutNode,
} from '@/features/layout/layoutModelSlice';
import type { CollapsibleTriggerProps } from '@radix-ui/react-collapsible';
import { CollapsibleContent } from '@radix-ui/react-collapsible';
import ComponentContextMenu, {
  ComponentContextMenuContent,
} from '@/features/layout/preview/ComponentContextMenu';
import useGetComponentName from '@/hooks/useGetComponentName';
import * as Collapsible from '@radix-ui/react-collapsible';
import SlotLayer from '@/features/layout/layers/SlotLayer';
import { useNavigationUtils } from '@/hooks/useNavigationUtils';
import useXbParams from '@/hooks/useXbParams';
import styles from './ComponentLayer.module.css';
import clsx from 'clsx';

interface ComponentLayerProps {
  component: ComponentNode;
  children?: false | React.ReactElement<CollapsibleTriggerProps>;
  indent: number;
  parentNode?: LayoutNode;
}

const ComponentLayer: React.FC<ComponentLayerProps> = ({
  component,
  indent,
}) => {
  const dispatch = useAppDispatch();
  const { componentId: selectedComponent } = useXbParams();
  const hoveredComponent = useAppSelector(selectHoveredComponent);
  const [open, setOpen] = useState(false);
  const { setSelectedComponent } = useNavigationUtils();

  const componentId = component.uuid;
  const nodeName = useGetComponentName(component);

  function handleItemClick(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    setSelectedComponent(componentId);
  }

  function handleItemMouseEnter(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(setHoveredComponent(componentId));
  }

  function handleItemMouseLeave(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(unsetHoveredComponent());
  }

  function handleItemDragStart(event: React.DragEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(unsetHoveredComponent());
    customSortableDragImage(event, window.document, nodeName);
  }

  function handleContextMenu(event: React.MouseEvent<HTMLDivElement>) {
    event.preventDefault();
    event.stopPropagation();
  }

  return (
    <Box
      data-xb-uuid={componentId}
      data-xb-type={component.nodeType}
      data-xb-selected={selectedComponent === componentId}
      onClick={handleItemClick}
      onDragStart={handleItemDragStart}
      onContextMenu={handleContextMenu}
      aria-labelledby={`layer-${componentId}-name`}
    >
      <ComponentContextMenu component={component}>
        <Collapsible.Root
          className="xb--collapsible-root"
          open={open}
          onOpenChange={setOpen}
          data-xb-uuid={component.uuid}
        >
          <SidebarNode
            id={`layer-${componentId}-name`}
            onMouseEnter={handleItemMouseEnter}
            onMouseLeave={handleItemMouseLeave}
            className="xb-drag-handle"
            title={nodeName}
            variant="component"
            hovered={hoveredComponent === componentId}
            selected={selectedComponent === componentId}
            open={open}
            dropdownMenuContent={
              <ComponentContextMenuContent
                component={component}
                menuType="dropdown"
              />
            }
            leadingContent={
              <Flex>
                <Box
                  width={`calc(${indent} * var(--space-2))`}
                  className="xb-layer-indent"
                />
                <Box width="var(--space-4)" mr="1">
                  {component.slots.length > 0 ? (
                    <Collapsible.Trigger asChild={true}>
                      <button
                        aria-label={
                          open
                            ? `Collapse component tree`
                            : `Expand component tree`
                        }
                      >
                        {open ? <TriangleDownIcon /> : <TriangleRightIcon />}
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
              className={clsx(
                selectedComponent === componentId &&
                  styles.componentChildrenSelected,
              )}
            >
              {component.slots.map((slot) => (
                <SlotLayer
                  key={slot.id}
                  slot={slot}
                  indent={indent + 1}
                  parentNode={component}
                />
              ))}
            </CollapsibleContent>
          )}
        </Collapsible.Root>
      </ComponentContextMenu>
    </Box>
  );
};

export default ComponentLayer;
