import styles from './SortableContainer.module.css';
import type React from 'react';
import { useRef, useEffect } from 'react';
import Sortable from 'sortablejs';
import { useAppSelector } from '@/app/hooks';
import type { LayoutNode } from '@/features/layout/layoutModelSlice';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import clsx from 'clsx';
import TreeNode from '@/features/layout/tree/TreeNode';
import useSortable from '@/features/layout/tree/useSortable';

interface TreeParentProps {
  node: LayoutNode;
}

const SortableContainer: React.FC<TreeParentProps> = (props) => {
  const { node } = props;
  const layout = useAppSelector(selectLayout);
  const listElRef = useRef<HTMLUListElement | null>(null);
  const sortableInstance = useRef<Sortable | null>(null);
  const { handleDragAdd, handleDragStart, handleDragEnd } = useSortable();

  useEffect(() => {
    if (listElRef?.current !== null) {
      sortableInstance.current = Sortable.create(
        listElRef.current as HTMLUListElement,
        {
          dataIdAttr: 'data-xb-uuid',
          animation: 0,
          ghostClass: styles.sortableGhost,
          onAdd: handleDragAdd,
          onStart: handleDragStart,
          onEnd: handleDragEnd,
          draggable: '.treeNode',
          group: {
            name: 'tree',
            put: ['tree'],
          },
        },
      );
    }
    return () => {
      if (sortableInstance.current instanceof Sortable) {
        sortableInstance.current.destroy();
      }
    };
  }, [layout, handleDragAdd, handleDragEnd, handleDragStart, node.nodeType]);

  return (
    <ul
      className={clsx(styles.treeParent, {
        [styles.slot]: node.nodeType === 'slot',
      })}
      ref={listElRef}
      data-xb-uuid={node.uuid}
    >
      {node.children.map((child) => {
        return <TreeNode node={child} key={child.uuid} />;
      })}
    </ul>
  );
};

export default SortableContainer;
