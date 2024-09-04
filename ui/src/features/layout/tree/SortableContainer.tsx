import styles from './SortableContainer.module.css';
import type React from 'react';
import { useRef, useEffect, useState } from 'react';
import Sortable from 'sortablejs';
import { useAppSelector } from '@/app/hooks';
import type { LayoutNode } from '@/features/layout/layoutModelSlice';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import useSortable from '@/features/layout/tree/useSortable';
import * as Collapsible from '@radix-ui/react-collapsible';
import TreeItem from '@/features/layout/tree/TreeItem';
import { TriangleDownIcon, TriangleRightIcon } from '@radix-ui/react-icons';
import clsx from 'clsx';

interface SortableContainerProps {
  node: LayoutNode;
}

const SortableContainer: React.FC<SortableContainerProps> = (props) => {
  const { node } = props;
  const layout = useAppSelector(selectLayout);
  const sortableRef = useRef<HTMLDivElement | null>(null);
  const sortableInstance = useRef<Sortable | null>(null);
  const { handleDragAdd, handleDragStart, handleDragEnd } = useSortable();
  const [open, setOpen] = useState(false);
  const isSlotEmpty = node.children.length === 0;

  useEffect(() => {
    if (sortableRef?.current !== null) {
      sortableInstance.current = Sortable.create(
        sortableRef.current as HTMLDivElement,
        {
          dataIdAttr: 'data-xb-uuid',
          animation: 0,
          ghostClass: styles.sortableGhost,
          onAdd: handleDragAdd,
          onStart: handleDragStart,
          onEnd: handleDragEnd,
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
  }, [layout, handleDragAdd, handleDragEnd, handleDragStart]);

  const renderChildren = (children: LayoutNode[]) => {
    return children.map((child: LayoutNode) => {
      if (child.nodeType === 'slot' || child.children?.length > 0) {
        return <SortableContainer node={child} key={child.uuid} />;
      } else {
        return <TreeItem node={child} key={child.uuid} />;
      }
    });
  };

  // Don't display the root as an item in the layers view so just render its children.
  // But attach the root id and the sortable ref to it, so we can still drag items into the root level.
  if (node.nodeType === 'root') {
    return (
      <div
        data-xb-uuid={node.uuid}
        data-xb-type={node.nodeType}
        ref={sortableRef}
        className={clsx('rootNodeWrapper', styles.rootNodeWrapper)}
      >
        {renderChildren(node.children)}
      </div>
    );
  }

  return (
    <Collapsible.Root
      className="CollapsibleRoot"
      open={open}
      onOpenChange={setOpen}
      asChild={true}
    >
      <>
        <TreeItem node={node}>
          {/* Only show the trigger if the slot has children */}
          {!isSlotEmpty && (
            <Collapsible.Trigger asChild={true}>
              <button>
                {open ? <TriangleDownIcon /> : <TriangleRightIcon />}
              </button>
            </Collapsible.Trigger>
          )}
        </TreeItem>
        {/* Only allow nodeType of slot (not component) to receive dragged items */}
        <Collapsible.Content
          ref={node.nodeType === 'slot' ? sortableRef : null}
          data-xb-uuid={node.uuid}
        >
          {renderChildren(node.children)}
        </Collapsible.Content>
      </>
    </Collapsible.Root>
  );
};

export default SortableContainer;
