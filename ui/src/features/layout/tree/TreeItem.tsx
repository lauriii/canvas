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
import type { LayoutNode } from '@/features/layout/layoutModelSlice';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import { getNodeDepth } from '@/features/layout/layoutUtils';
import type { CollapsibleTriggerProps } from '@radix-ui/react-collapsible';
import ComponentContextMenu from '@/features/layout/preview/ComponentContextMenu';

interface TreeItemProps {
  node: LayoutNode;
  children?: false | React.ReactElement<CollapsibleTriggerProps>;
}

const TreeItem: React.FC<TreeItemProps> = ({ node, children }) => {
  const dispatch = useAppDispatch();
  const model = useAppSelector(selectModel);
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const hoveredComponent = useAppSelector(selectHoveredComponent);
  const layout = useAppSelector(selectLayout);
  const nodeName = model[node.uuid] ? model[node.uuid].name : 'Slot';
  const isSlot = node.nodeType === 'slot';
  const IconComponent = isSlot ? BoxModelIcon : ComponentInstanceIcon;
  // Calculate the padding left value based on the depth of the node in the tree.
  const paddingLeftValue = getNodeDepth(layout, node.uuid) * 15;

  function handleItemClick(event: React.MouseEvent<HTMLDivElement>) {
    if (isSlot) {
      return;
    }
    event.stopPropagation();
    dispatch(setSelectedComponent(node.uuid));
  }

  function handleItemMouseEnter(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(setHoveredComponent(node.uuid));
  }

  function handleItemMouseLeave(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(unsetHoveredComponent());
  }

  function handleItemDragStart(event: React.DragEvent<HTMLDivElement>) {
    event.stopPropagation();
    // Clear hovered component to avoid interference with SortableJS setting a ghost class.
    dispatch(unsetHoveredComponent());
    customSortableDragImage(event, window.document, model[node.uuid].name);
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
          <Text size="1" id={`layer-${node.uuid}-name`}>
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
          [styles.selected]: selectedComponent === node.uuid,
          [styles.hovered]: hoveredComponent === node.uuid,
        },
        styles.treeItem,
      )}
      style={{
        paddingLeft: `${paddingLeftValue}px`,
      }}
      data-xb-uuid={node.uuid}
      data-xb-type={node.nodeType}
      onClick={handleItemClick}
      onMouseEnter={handleItemMouseEnter}
      onMouseLeave={handleItemMouseLeave}
      onDragStart={handleItemDragStart}
      onContextMenu={handleContextMenu}
      aria-labelledby={`layer-${node.uuid}-name`}
    >
      {!isSlot ? (
        <ComponentContextMenu componentUuid={node.uuid}>
          {treeItem}
        </ComponentContextMenu>
      ) : (
        treeItem
      )}
    </div>
  );
};

export default TreeItem;
