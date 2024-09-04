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
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import type { CollapsibleTriggerProps } from '@radix-ui/react-collapsible';

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
  // Get the depth of the node in the layout tree from the root and use that to calculate
  // the padding left value in the layers view.
  const depth = () => {
    const path = findNodePathByUuid(layout, node.uuid);
    if (path) {
      return path.length - 1;
    }
    return 0;
  };
  const nodeName = node.name ?? model[node.uuid].name;
  const isSlot = node.nodeType === 'slot';
  const IconComponent = isSlot ? BoxModelIcon : ComponentInstanceIcon;

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
      style={{ paddingLeft: `${depth() * 15}px` }}
      data-xb-uuid={node.uuid}
      data-xb-type={node.nodeType}
      onClick={handleItemClick}
      onMouseEnter={handleItemMouseEnter}
      onMouseLeave={handleItemMouseLeave}
      onDragStart={(event) =>
        customSortableDragImage(event, window.document, model[node.uuid].name)
      }
    >
      <Flex>
        <Box width="10px" pr="5">
          {children}
        </Box>
        <Box>
          <div className={clsx(styles.inline)}>
            <IconComponent className={clsx(styles.icon)} />
            <Text size="1">{nodeName}</Text>
          </div>
        </Box>
      </Flex>
    </div>
  );
};

export default TreeItem;
