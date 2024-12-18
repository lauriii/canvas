// @ts-nocheck
import styles from './SortableContainer.module.css';
import type React from 'react';
import { useRef, useEffect, useState } from 'react';
import Sortable from 'sortablejs';
import { useAppSelector } from '@/app/hooks';
import type {
  ComponentNode,
  LayoutNode,
} from '@/features/layout/layoutModelSlice';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import useSortable from '@/features/layout/tree/useSortable';
import * as Collapsible from '@radix-ui/react-collapsible';
import TreeItem from '@/features/layout/tree/TreeItem';
import { TriangleDownIcon, TriangleRightIcon } from '@radix-ui/react-icons';
import clsx from 'clsx';
import { getNodeDepth } from '@/features/layout/layoutUtils';
import { Box } from '@radix-ui/themes';
import RegionItem from '@/features/layout/tree/RegionItem';

interface SortableContainerProps {
  node: LayoutNode;
  parentNode?: ComponentNode;
  setDragging: React.Dispatch<React.SetStateAction<boolean>>;
}

const SortableContainer: React.FC<SortableContainerProps> = (props) => {
  const { node, setDragging, parentNode } = props;
  const layout = useAppSelector(selectLayout);
  const sortableRef = useRef<HTMLDivElement | null>(null);
  const sortableInstance = useRef<Sortable | null>(null);
  const { handleDragAdd, handleDragStart, handleDragEnd } = useSortable();
  const [open, setOpen] = useState(false);
  const isSlotEmpty = node.components?.length === 0;

  useEffect(() => {
    if (sortableRef?.current !== null) {
      sortableInstance.current = Sortable.create(
        sortableRef.current as HTMLDivElement,
        {
          dataIdAttr: 'data-xb-uuid',
          animation: 0,
          onAdd: handleDragAdd,
          invertSwap: true,
          ghostClass: styles.xbCustomGhost,
          onStart: () => {
            handleDragStart();
            setDragging(true);
          },
          onMove: (evt) => {
            const uuid = evt.to.dataset.xbUuid;
            const targetDepth = getNodeDepth(layout, uuid) + 1;
            const offsetVal = targetDepth * 15 + 57;
            // Calculate the width of the ghost element (thin blue line)
            // based on the depth of the target slot in the tree.
            evt.dragged.style.width = `calc(100% - ${offsetVal}px)`;
          },
          onEnd: (evt) => {
            handleDragEnd(evt);
            setDragging(false);
            // Reset the width on end because we manually set the ghost width based on
            // the target slot in the onMove option.
            evt.item.style.width = 'initial';
          },
          swapThreshold: 0.65,
          group: {
            name: 'tree',
          },
          // Keep a clone element in the original position until the drag ends.
          removeCloneOnHide: false,
          onClone: (evt) => {
            evt.clone.classList.add(styles.xbCustomClone);
          },
          // Don't allow dragging a slot.
          filter: '[data-xb-type="slot"]',
        },
      );
    }
    return () => {
      if (sortableInstance.current instanceof Sortable) {
        sortableInstance.current.destroy();
      }
    };
  }, [layout, handleDragAdd, handleDragEnd, handleDragStart, setDragging]);

  const renderChildren = (children: Node[], parentNode?: Node) => {
    return children.map((child: Node) => {
      if (
        child.nodeType === 'slot' ||
        child.components?.length > 0 ||
        child.slots?.length > 0
      ) {
        return (
          <SortableContainer
            setDragging={setDragging}
            node={child}
            key={child.uuid || child.id}
            parentNode={parentNode}
          />
        );
      } else {
        return (
          <TreeItem node={child} key={child.uuid} parentNode={parentNode} />
        );
      }
    });
  };

  // Don't display the root as an item in the layers view so just render its children.
  // But attach the root id and the sortable ref to it, so we can still drag items into the root level.
  if (node.nodeType === 'region') {
    return (
      <>
        <RegionItem region={node.name} />
        <Box
          data-xb-uuid={node.uuid}
          data-xb-type={node.nodeType}
          ref={sortableRef}
          className="rootDropZone"
          py="4"
        >
          {renderChildren(node.components, node)}
        </Box>
      </>
    );
  }

  return (
    <Collapsible.Root
      className="xb--collapsible-root"
      open={open}
      onOpenChange={setOpen}
      data-xb-uuid={node.uuid || node.id}
    >
      <TreeItem node={node} parentNode={parentNode}>
        {/* Only show the trigger if the slot has children */}
        {!isSlotEmpty && (
          <Collapsible.Trigger asChild={true}>
            <button
              aria-label={
                open ? `Collapse component tree` : `Expand component tree`
              }
            >
              {open ? <TriangleDownIcon /> : <TriangleRightIcon />}
            </button>
          </Collapsible.Trigger>
        )}
      </TreeItem>
      {/* Only allow nodeType of slot (not component) to receive dragged items */}
      <div
        ref={node.nodeType === 'slot' ? sortableRef : null}
        className={clsx(
          node.nodeType === 'slot' && styles.slotDropZone,
          'slotDropZone',
        )}
        data-xb-uuid={node.uuid || node.id}
      >
        <Collapsible.Content asChild={true}>
          <>{renderChildren(node.components || node.slots, node)}</>
        </Collapsible.Content>
      </div>
    </Collapsible.Root>
  );
};

export default SortableContainer;
