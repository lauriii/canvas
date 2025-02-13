import { useRef, useEffect } from 'react';
import clsx from 'clsx';
import Sortable from 'sortablejs';
import { Button, Flex } from '@radix-ui/themes';
import {
  DragHandleDots2Icon,
  PlusIcon,
  TrashIcon,
} from '@radix-ui/react-icons';
import styles from './SortableList.module.css';

interface SortableListProps<T> {
  items: T[];
  onAdd: () => void;
  onReorder: (oldIndex: number, newIndex: number) => void;
  onRemove: (id: string) => void;
  renderContent: (item: T) => React.ReactNode;
  getItemId: (item: T) => string;
  'data-testid'?: string;
  moveAriaLabel?: string;
  removeAriaLabel?: string;
}

export default function SortableList<T>({
  items,
  onAdd,
  onReorder,
  onRemove,
  renderContent,
  getItemId,
  'data-testid': dataTestId,
  moveAriaLabel = 'Move item',
  removeAriaLabel = 'Remove item',
}: SortableListProps<T>) {
  const sortableRef = useRef<HTMLDivElement>(null);
  const sortableInstance = useRef<Sortable | null>(null);

  useEffect(() => {
    if (sortableRef?.current) {
      sortableInstance.current = Sortable.create(sortableRef.current, {
        animation: 150,
        handle: `.${styles.moveControl}`,
        ghostClass: styles.sortableGhost,
        onEnd: (evt) => {
          const { oldIndex, newIndex } = evt;
          if (oldIndex === undefined || newIndex === undefined) return;
          onReorder(oldIndex, newIndex);
        },
      });
    }

    return () => {
      if (sortableInstance.current) {
        sortableInstance.current.destroy();
      }
    };
  }, [onReorder]);

  return (
    <Flex
      ref={sortableRef}
      direction="column"
      gap="4"
      p="4"
      mx="auto"
      maxWidth="500px"
    >
      {items.map((item, index) => (
        <SortablePanel
          key={getItemId(item)}
          onRemove={() => onRemove(getItemId(item))}
          data-testid={dataTestId ? `${dataTestId}-${index}` : undefined}
          moveAriaLabel={moveAriaLabel}
          removeAriaLabel={removeAriaLabel}
        >
          {renderContent(item)}
        </SortablePanel>
      ))}
      <Button size="1" variant="soft" mb="4" onClick={onAdd}>
        <PlusIcon />
        Add
      </Button>
    </Flex>
  );
}

interface SortablePanelProps {
  children: React.ReactNode;
  onRemove: () => void;
  'data-testid'?: string;
  moveAriaLabel?: string;
  removeAriaLabel?: string;
}

function SortablePanel({
  children,
  onRemove,
  'data-testid': dataTestId,
  moveAriaLabel,
  removeAriaLabel,
}: SortablePanelProps) {
  return (
    <Flex
      p="4"
      pl="2"
      gap="4"
      className={styles.panel}
      data-testid={dataTestId}
    >
      <Flex
        direction="column"
        justify="between"
        align="center"
        flexGrow="0"
        flexShrink="0"
      >
        <Button
          aria-label={moveAriaLabel}
          variant="ghost"
          color="gray"
          className={clsx(styles.moveRemoveControls, styles.moveControl)}
        >
          <DragHandleDots2Icon />
        </Button>
        <Button
          onClick={onRemove}
          aria-label={removeAriaLabel}
          variant="ghost"
          color="red"
          className={styles.moveRemoveControls}
        >
          <TrashIcon />
        </Button>
      </Flex>
      <Flex direction="column" flexGrow="1">
        {children}
      </Flex>
    </Flex>
  );
}
