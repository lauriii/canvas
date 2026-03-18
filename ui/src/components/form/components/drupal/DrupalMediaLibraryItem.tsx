import { useId } from 'react';
import clsx from 'clsx';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { DragHandleDots2Icon } from '@radix-ui/react-icons';

import { a2p } from '@/local_packages/utils';

import type { ReactNode } from 'react';

import styles from './DrupalMediaLibraryItem.module.css';

interface DrupalMediaLibraryItemProps {
  id?: string; // Optional: unique ID for sorting (will be auto-generated if not provided)
  children: ReactNode;
  attributes?: Record<string, any>;
  itemIndex: number;
}

const DrupalMediaLibraryItem = ({
  id: providedId,
  itemIndex,
  children,
  attributes = {},
}: DrupalMediaLibraryItemProps) => {
  const autoId = useId();
  const id = providedId || `media-item-${autoId}`;
  const isMultiple =
    Boolean(attributes['data-is-multiple']) &&
    attributes['data-is-multiple'] !== 'false';

  // Use useSortable directly in this component
  const {
    attributes: sortableAttributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({
    id,
    transition: {
      duration: 200,
      easing: 'cubic-bezier(0.25, 1, 0.5, 1)',
    },
  });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  return (
    <div
      data-item-index={itemIndex}
      style={style}
      {...a2p(attributes, {}, { skipAttributes: ['class'] })}
      className={clsx(styles.item, attributes.class)}
    >
      {isMultiple && (
        <div
          ref={setNodeRef}
          className={styles.dragHandle}
          data-canvas-drag-handle
          aria-label="Drag to reorder"
          {...sortableAttributes}
          {...listeners}
          tabIndex={0}
          role="button"
          data-canvas-is-dragging={isDragging}
        >
          <DragHandleDots2Icon />
        </div>
      )}
      {children}
    </div>
  );
};

export default DrupalMediaLibraryItem;
