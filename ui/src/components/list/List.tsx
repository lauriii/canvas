import type React from 'react';
import { useRef, useMemo } from 'react';
import styles from './List.module.css';
import { selectDragging } from '@/features/ui/uiSlice';
import { useAppSelector } from '@/app/hooks';
import { Box, Flex, Spinner } from '@radix-ui/themes';
import clsx from 'clsx';
import ListItem from '@/components/list/ListItem';
import type { ComponentsList } from '@/types/Component';
import type { SectionsList } from '@/types/Section';

export interface ListProps {
  items: ComponentsList | SectionsList | undefined;
  isLoading: boolean;
  type: 'component' | 'section';
  label: string;
}

const List: React.FC<ListProps> = (props) => {
  const { items, isLoading, type } = props;
  const listElRef = useRef<HTMLDivElement>(null);
  const { isDragging } = useAppSelector(selectDragging);

  // Sort items and convert to array.
  const sortedItems = useMemo(() => {
    return items
      ? Object.entries(items).sort(([, a], [, b]) =>
          a.name.localeCompare(b.name),
        )
      : [];
  }, [items]);

  return (
    <div className={clsx('listContainer', styles.listContainer)}>
      <Box className={isDragging ? 'list-dragging' : ''}>
        <Spinner loading={isLoading}>
          <Flex direction="column" width="100%" ref={listElRef}>
            {sortedItems &&
              sortedItems.map(([id, item]) => (
                <ListItem item={item} key={id} type={type} />
              ))}
          </Flex>
        </Spinner>
      </Box>
    </div>
  );
};

export default List;
