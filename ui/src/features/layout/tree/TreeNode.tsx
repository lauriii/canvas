import type React from 'react';
import styles from './TreeNode.module.css';
import type { LayoutNode } from '@/features/layout/layoutModelSlice';
import { selectModel } from '@/features/layout/layoutModelSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { Box, Flex, Text } from '@radix-ui/themes';
import {
  ComponentInstanceIcon,
  TriangleDownIcon,
  TriangleRightIcon,
} from '@radix-ui/react-icons';
import clsx from 'clsx';
import {
  selectSelectedComponent,
  selectHoveredComponent,
  setHoveredComponent,
  setSelectedComponent,
} from '@/features/ui/uiSlice';
import { customSortableDragImage } from '@/features/sortable/sortableUtils';
import { useState } from 'react';
import SortableContainer from '@/features/layout/tree/SortableContainer';
import * as Collapsible from '@radix-ui/react-collapsible';

interface TreeNodeProps {
  node: LayoutNode;
}
const TreeNode: React.FC<TreeNodeProps> = (props) => {
  const { node } = props;
  const model = useAppSelector(selectModel);
  const dispatch = useAppDispatch();
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const hoveredComponent = useAppSelector(selectHoveredComponent);
  const [open, setOpen] = useState(false);
  const isCollapsible = node.children && node.children.length > 0;

  function handleItemClick(event: React.MouseEvent<HTMLLIElement>) {
    event.stopPropagation();
    dispatch(setSelectedComponent(node.uuid));
  }

  function handleItemMouseEnter(event: React.MouseEvent<HTMLLIElement>) {
    event.stopPropagation();
    dispatch(setHoveredComponent(node.uuid));
  }

  const TreeNodeContent = () => (
    <Flex>
      <Box width="10px" pr="5">
        {isCollapsible && (
          <Collapsible.Trigger asChild>
            <button>
              {open ? <TriangleDownIcon /> : <TriangleRightIcon />}
            </button>
          </Collapsible.Trigger>
        )}
      </Box>
      <Box>
        <div style={{ display: 'inline-flex' }}>
          <ComponentInstanceIcon className={clsx(styles.treeChildIcon)} />
          <Text size="1">{node.name ?? model[node.uuid].name}</Text>
        </div>
        {isCollapsible && (
          <Collapsible.Content>
            {node.children.map((child) => (
              <TreeNode node={child} key={child.uuid} />
            ))}
          </Collapsible.Content>
        )}
      </Box>
    </Flex>
  );

  const ListItem = () => {
    return (
      <li
        className={clsx(
          'treeNode',
          {
            [styles.selected]: selectedComponent === node.uuid,
            [styles.hovered]: hoveredComponent === node.uuid,
          },
          styles.treeNode,
        )}
        data-xb-uuid={node.uuid}
        data-xb-type={node.nodeType}
        onClick={handleItemClick}
        onMouseEnter={handleItemMouseEnter}
        onDragStart={(event) =>
          customSortableDragImage(event, window.document, model[node.uuid].name)
        }
      >
        <TreeNodeContent />
      </li>
    );
  };

  if (node.nodeType === 'slot') {
    return <SortableContainer node={node} />;
  }

  if (isCollapsible) {
    return (
      <Collapsible.Root
        className="CollapsibleRoot"
        open={open}
        onOpenChange={setOpen}
        asChild={true}
      >
        <ListItem />
      </Collapsible.Root>
    );
  }

  return <ListItem />;
};

export default TreeNode;
