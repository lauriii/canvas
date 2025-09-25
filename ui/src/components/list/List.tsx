import React, { useRef } from 'react';
import clsx from 'clsx';
import { Flex } from '@radix-ui/themes';

import ListItem from '@/components/list/ListItem';

import type { LayoutItemType } from '@/features/ui/primaryPanelSlice';
import type { ComponentsList } from '@/types/Component';
import type { PatternsList } from '@/types/Pattern';

import styles from './List.module.css';

export interface ListProps {
  items: ComponentsList | PatternsList | undefined;
  type:
    | LayoutItemType.COMPONENT
    | LayoutItemType.PATTERN
    | LayoutItemType.DYNAMIC;
  renderItem: (item: any) => React.ReactNode;
}

const List: React.FC<ListProps> = (props) => {
  const { items, type, renderItem } = props;
  const listElRef = useRef<HTMLDivElement>(null);

  return (
    <div className={clsx('listContainer', styles.listContainer)}>
      <Flex direction="column" width="100%" ref={listElRef} role="list">
        {items &&
          Object.entries(items).map(([id, item]) =>
            renderItem ? (
              <React.Fragment key={id}>{renderItem(item)}</React.Fragment>
            ) : (
              <ListItem item={item} key={id} type={type} />
            ),
          )}
      </Flex>
    </div>
  );
};

export default List;
