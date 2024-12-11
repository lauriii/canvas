import type React from 'react';
import clsx from 'clsx';
import { Flex, Box, Text } from '@radix-ui/themes';
import { ComponentInstanceIcon, BoxModelIcon } from '@radix-ui/react-icons';
import styles from './TreeItem.module.css';
import { customSortableDragImage } from '@/features/sortable/sortableUtils';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectHoveredComponent,
  selectSelectedComponent,
  setHoveredComponent,
  setSelectedComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import type {
  LayoutChildNode,
  LayoutNode,
} from '@/features/layout/layoutModelSlice';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { getNodeDepth } from '@/features/layout/layoutUtils';
import type { CollapsibleTriggerProps } from '@radix-ui/react-collapsible';
import ComponentContextMenu from '@/features/layout/preview/ComponentContextMenu';
import useGetComponentName from '@/hooks/useGetComponentName';

interface TreeItemProps {
  node: LayoutChildNode;
  children?: false | React.ReactElement<CollapsibleTriggerProps>;
  parentNode?: LayoutNode;
}

const TreeItem: React.FC<TreeItemProps> = ({ node, children, parentNode }) => {
  const dispatch = useAppDispatch();
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const hoveredComponent = useAppSelector(selectHoveredComponent);
  const layout = useAppSelector(selectLayout);
  const isSlot = node.nodeType === 'slot';
  const nodeName = useGetComponentName(node, isSlot ? parentNode : undefined);

  // TODO refactor of this file should remove this ts-ignore once Slots and Component rendering is correctly separated out.
  //@ts-ignore
  const nodeId = node.uuid || node.id || node.name;

  const IconComponent = isSlot ? BoxModelIcon : ComponentInstanceIcon;
  // Calculate the padding left value based on the depth of the node in the tree.
  const paddingLeftValue = getNodeDepth(layout, nodeId) * 15;

  function handleItemClick(event: React.MouseEvent<HTMLDivElement>) {
    if (isSlot) {
      return;
    }
    event.stopPropagation();
    dispatch(setSelectedComponent(nodeId));
  }

  function handleItemMouseEnter(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(setHoveredComponent(nodeId));
  }

  function handleItemMouseLeave(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(unsetHoveredComponent());
  }

  function handleItemDragStart(event: React.DragEvent<HTMLDivElement>) {
    event.stopPropagation();
    // Clear hovered component to avoid interference with SortableJS setting a ghost class.
    dispatch(unsetHoveredComponent());
    customSortableDragImage(event, window.document, nodeName);
  }

  function handleContextMenu(event: React.MouseEvent<HTMLDivElement>) {
    if (isSlot) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
  }

  const treeItem = (
    <Flex>
      <Box width="10px" pr="5">
        {children}
      </Box>
      <Box>
        <div className={clsx(styles.inline)}>
          <IconComponent className={clsx(styles.icon, 'icon')} />
          <Text size="1" id={`layer-${nodeId}-name`}>
            {nodeName}
          </Text>
        </div>
      </Box>
    </Flex>
  );

  return (
    <div
      className={clsx(
        'treeItem',
        {
          [styles.selected]: selectedComponent === nodeId,
          [styles.hovered]: hoveredComponent === nodeId,
        },
        styles.treeItem,
      )}
      style={{
        paddingLeft: `${paddingLeftValue}px`,
      }}
      data-xb-uuid={nodeId}
      data-xb-type={node.nodeType}
      data-xb-selected={selectedComponent === nodeId}
      onClick={handleItemClick}
      onMouseEnter={handleItemMouseEnter}
      onMouseLeave={handleItemMouseLeave}
      onDragStart={handleItemDragStart}
      onContextMenu={handleContextMenu}
      aria-labelledby={`layer-${nodeId}-name`}
    >
      {!isSlot ? (
        <ComponentContextMenu component={node}>{treeItem}</ComponentContextMenu>
      ) : (
        treeItem
      )}
    </div>
  );
};

export default TreeItem;
