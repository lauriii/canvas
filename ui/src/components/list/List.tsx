import type React from 'react';
import { useRef, useMemo } from 'react';
import styles from './List.module.css';
import { selectDragging } from '@/features/ui/uiSlice';
import { useAppSelector } from '@/app/hooks';
import { Box, Flex, Skeleton } from '@radix-ui/themes';
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
        <Skeleton
          data-testid="xb-components-library-loading"
          loading={isLoading}
          height="1.2rem"
          width="100%"
          my="3"
        >
          <Flex direction="column" width="100%" ref={listElRef} role="list">
            {sortedItems &&
              sortedItems.map(([id, item]) => (
                <ListItem item={item} key={id} type={type} />
              ))}
          </Flex>
        </Skeleton>
        <Skeleton loading={isLoading} height="1.2rem" width="100%" my="3" />
        <Skeleton loading={isLoading} height="1.2rem" width="100%" my="3" />
      </Box>
    </div>
  );
};

export default List;
