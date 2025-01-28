import type React from 'react';
import { useState } from 'react';
import clsx from 'clsx';
import { Flex, Box, Text } from '@radix-ui/themes';
import {
  ComponentInstanceIcon,
  TriangleDownIcon,
  TriangleRightIcon,
} from '@radix-ui/react-icons';
import styles from './Layers.module.css';
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
import ComponentContextMenu from '@/features/layout/preview/ComponentContextMenu';
import useGetComponentName from '@/hooks/useGetComponentName';
import * as Collapsible from '@radix-ui/react-collapsible';
import SlotLayer from '@/features/layout/layers/SlotLayer';
import { useNavigationUtils } from '@/hooks/useNavigationUtils';
import { useParams } from 'react-router-dom';

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
  const { componentId: selectedComponent } = useParams();
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
          <Flex
            py="2"
            pl="2"
            align="start"
            onMouseEnter={handleItemMouseEnter}
            onMouseLeave={handleItemMouseLeave}
            className={clsx(
              'xb-drag-handle',
              {
                [styles.selected]: selectedComponent === componentId,
                [styles.hovered]: hoveredComponent === componentId,
              },
              styles.layer,
              styles.componentLayer,
            )}
          >
            <Box
              width={`calc(${indent} * var(--space-2))`}
              className="xb-layer-indent"
            />
            <Box width="var(--space-4)" mr="1">
              {component.slots.length > 0 ? (
                <Collapsible.Trigger asChild={true}>
                  <button
                    aria-label={
                      open ? `Collapse component tree` : `Expand component tree`
                    }
                  >
                    {open ? <TriangleDownIcon /> : <TriangleRightIcon />}
                  </button>
                </Collapsible.Trigger>
              ) : (
                <Box />
              )}
            </Box>
            <Box width="var(--space-4)" mr="2">
              <ComponentInstanceIcon
                className={clsx(styles.componentIcon, 'xb-componentIcon')}
              />
            </Box>
            <Text size="1" id={`layer-${componentId}-name`}>
              {nodeName}
            </Text>
          </Flex>
          {component.slots.length > 0 && (
            <CollapsibleContent>
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
