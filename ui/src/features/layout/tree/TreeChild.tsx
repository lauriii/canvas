import type React from 'react';
import styles from './TreeChild.module.css';
import TreeParent from './TreeParent';
import type { LayoutNode } from '@/features/layout/layoutModelSlice';
import { deleteNode, selectModel } from '@/features/layout/layoutModelSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { IconButton, Text } from '@radix-ui/themes';
import { TrashIcon } from '@radix-ui/react-icons';
import clsx from 'clsx';
import {
  selectSelectedComponent,
  selectHoveredComponent,
  setSelectedComponent,
  setHoveredComponent,
} from '@/features/ui/uiSlice';

import { customSortableDragImage } from '@/features/sortable/sortableUtils';

interface TreeChildProps {
  node: LayoutNode;
}
const TreeChild: React.FC<TreeChildProps> = (props) => {
  const { node } = props;
  const model = useAppSelector(selectModel);
  const dispatch = useAppDispatch();
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const hoveredComponent = useAppSelector(selectHoveredComponent);

  function handleItemClick(event: React.MouseEvent<HTMLLIElement>) {
    event.stopPropagation();
    dispatch(setSelectedComponent(node.uuid));
  }

  function handleItemMouseEnter(event: React.MouseEvent<HTMLLIElement>) {
    event.stopPropagation();
    dispatch(setHoveredComponent(node.uuid));
  }
  function handleDeleteClick() {
    dispatch(deleteNode(node.uuid));
  }

  return (
    <li
      data-xb-uuid={node.uuid}
      data-xb-type={node.nodeType}
      className={clsx({
        [styles.selected]: selectedComponent === node.uuid,
        [styles.hovered]: hoveredComponent === node.uuid,
      })}
      onClick={handleItemClick}
      onMouseEnter={handleItemMouseEnter}
      onDragStart={(event) =>
        customSortableDragImage(event, window.document, model[node.uuid].name)
      }
    >
      {node.nodeType !== 'slot' && (
        <div className={styles.treeChildToolbar}>
          <div>{model[node.uuid]?.name}</div>
          <IconButton size="1" type="button" onClick={handleDeleteClick}>
            <TrashIcon width="16" height="16" />
          </IconButton>
        </div>
      )}

      {node.nodeType === 'slot' && (
        <Text size="1" className={styles.treeChildToolbar}>
          {node.name}
        </Text>
      )}

      {node.children && <TreeParent node={node} />}
    </li>
  );
};

export default TreeChild;
