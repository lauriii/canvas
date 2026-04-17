import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import {
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
  DragHandleDots2Icon,
  PlusIcon,
  TrashIcon,
} from '@radix-ui/react-icons';
import { Box, Button, Flex, IconButton } from '@radix-ui/themes';

import { VALUE_MODE_UNLIMITED } from '@/types/CodeComponent';

import type { ReactNode } from 'react';
import type { ValueMode } from '@/types/CodeComponent';

import './PropValuesSortableList.css';

interface SortableItemProps {
  id: string | number;
  children: ReactNode;
  showDragHandle: boolean;
  isDisabled?: boolean;
}

/**
 * Internal sortable item component for drag-and-drop lists.
 */
function SortableItem({
  id,
  children,
  showDragHandle,
  isDisabled = false,
}: SortableItemProps) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id, disabled: isDisabled || !showDragHandle });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  return (
    <Flex
      ref={setNodeRef}
      style={style}
      gap="2"
      align="center"
      flexGrow="1"
      width="100%"
    >
      {showDragHandle && (
        <Box
          {...attributes}
          {...listeners}
          className={`sortable-drag-handle ${isDisabled ? 'disabled' : ''}`}
          aria-label="Drag to reorder"
          role="button"
          tabIndex={isDisabled ? -1 : 0}
        >
          <DragHandleDots2Icon className={isDisabled ? 'disabled' : ''} />
        </Box>
      )}
      {children}
    </Flex>
  );
}

interface SortableListProps {
  /** Array of items to render */
  items: (string | number)[];
  /** Render function for each item's input field */
  renderItem: (index: number) => ReactNode;
  /** Handler for drag end event */
  onDragEnd: (event: any) => void;
  /** Handler for removing an item (unlimited mode only) */
  onRemove?: (index: number) => void;
  /** Handler for adding a new item (unlimited mode only) */
  onAdd?: () => void;
  /** Whether the list is disabled */
  isDisabled?: boolean;
  /** Mode for multiple values in array props. See ValueMode type. */
  mode: ValueMode;
  /** Optional content rendered between the list and the Add button. */
  errorMessage?: ReactNode;
}

/**
 * Reusable sortable list component for array prop type forms.
 * Handles both limited and unlimited modes with drag-and-drop functionality.
 */
export function PropValuesSortableList({
  items,
  renderItem,
  onDragEnd,
  onRemove,
  onAdd,
  isDisabled = false,
  mode,
  errorMessage,
}: SortableListProps) {
  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  );

  const isItemDisabled = isDisabled || items.length <= 1;

  return (
    <Flex mt="1" direction="column" gap="2" flexGrow="1" width="100%">
      <DndContext
        sensors={sensors}
        collisionDetection={closestCenter}
        onDragEnd={onDragEnd}
      >
        <SortableContext items={items} strategy={verticalListSortingStrategy}>
          {items.map((item, index) => (
            <SortableItem
              key={item}
              id={item}
              showDragHandle={true}
              isDisabled={isItemDisabled}
            >
              {renderItem(index)}
              {mode === VALUE_MODE_UNLIMITED && onRemove && (
                <IconButton
                  size="1"
                  onClick={() => onRemove(index)}
                  disabled={isItemDisabled}
                  aria-label="Remove value"
                  className={`trash-icon-button ${isItemDisabled ? 'disabled' : ''}`}
                >
                  <TrashIcon />
                </IconButton>
              )}
            </SortableItem>
          ))}
        </SortableContext>
      </DndContext>
      {errorMessage}
      {mode === VALUE_MODE_UNLIMITED && onAdd && (
        <Button
          size="1"
          variant="soft"
          onClick={onAdd}
          disabled={isDisabled}
          aria-label="Add value"
        >
          <PlusIcon />
          Add value
        </Button>
      )}
    </Flex>
  );
}
