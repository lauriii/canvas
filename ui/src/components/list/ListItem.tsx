import type React from 'react';
import type {
  ComponentListItem,
  SectionListItem,
} from '@/components/list/List';
import { useState } from 'react';
import clsx from 'clsx';
import styles from '@/components/list/List.module.css';
import { customSortableDragImage } from '@/features/sortable/sortableUtils';
import * as Tooltip from '@radix-ui/react-tooltip';
import { Theme } from '@radix-ui/themes';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import {
  disableClickToInsert,
  LayoutItemType,
  selectClickToInsertState,
} from '@/features/ui/primaryPanelSlice';
import {
  addNewComponentToLayout,
  addNewSectionToLayout,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ComponentPreview from '@/components/ComponentPreview';
import SidebarNode from '@/components/sidebar/SidebarNode';
import { useNavigationUtils } from '@/hooks/useNavigationUtils';

const ListItem: React.FC<{
  item: ComponentListItem | SectionListItem;
  type: 'component' | 'section';
}> = (props) => {
  const { item, type } = props;
  const dispatch = useAppDispatch();
  const { isEnabled, originUUID, originLayoutItemType } = useAppSelector(
    selectClickToInsertState,
  );
  const layout = useAppSelector(selectLayout);
  const [previewingComponent, setPreviewingComponent] = useState<
    ComponentListItem | SectionListItem
  >();
  const { setSelectedComponent } = useNavigationUtils();

  const clickToInsertHandler = (newId: string) => {
    let path: number[] | null = [0];
    if (isEnabled) {
      path = findNodePathByUuid(layout, originUUID);
    }
    if (path) {
      const newPath = [...path];
      // First check if the selected node is a component (node with parent) or
      // a section (node without parent).
      if (originLayoutItemType === LayoutItemType.COMPONENT) {
        // Example: A path [0, 2] means a node is the 3rd child of the 1st parent.
        // So when a new node is added, it should be added as the 4th child of the 1st parent.
        // Therefore, the newly inserted node's path would be [0, 3].
        newPath[newPath.length - 1] += 1;
      } else {
        // If the selected node is in the root level, then the new node should
        // be added as a sibling.
        // Example: A path [1][2] means a node is the 3rd node inside the
        // second region. Therefore, the newly inserted node's path should be
        // [1][3].
        newPath[1] += 1;
      }

      if (type === 'component') {
        dispatch(
          addNewComponentToLayout(
            {
              to: newPath,
              component: item as ComponentListItem,
            },
            setSelectedComponent,
          ),
        );
      } else if (type === 'section') {
        dispatch(
          addNewSectionToLayout(
            {
              to: newPath,
              layoutModel: (item as SectionListItem).layoutModel,
            },
            setSelectedComponent,
          ),
        );
      }

      dispatch(disableClickToInsert());
    }
  };

  const handleMouseEnter = (component: ComponentListItem | SectionListItem) => {
    setPreviewingComponent(component);
  };

  return (
    <div
      key={item.id}
      data-xb-component-id={item.id}
      data-xb-name={item.name}
      data-xb-type={type}
      className={clsx(
        styles.listItem,
        // Highlight the first item in the list when click to insert is enabled to
        // let the user know that they can click to insert the item.
        // index === 0 && isEnabled ? styles.outline : null,
      )}
      onClick={() => clickToInsertHandler(item.id)}
      onDragStart={(event) =>
        customSortableDragImage(event, window.document, item.name)
      }
      onMouseEnter={() => handleMouseEnter(item)}
    >
      <Tooltip.Provider>
        <Tooltip.Root delayDuration={0}>
          <Tooltip.Trigger asChild>
            <SidebarNode
              title={item.name}
              variant={
                type === 'component' &&
                (item as ComponentListItem).source === 'Blocks'
                  ? 'blockComponent'
                  : type
              }
            />
          </Tooltip.Trigger>
          <Tooltip.Portal>
            <Tooltip.Content
              side="right"
              sideOffset={24}
              align="start"
              className={styles.componentPreviewTooltipContent}
            >
              <Theme>
                {previewingComponent && (
                  <ComponentPreview componentListItem={previewingComponent} />
                )}
              </Theme>
            </Tooltip.Content>
          </Tooltip.Portal>
        </Tooltip.Root>
      </Tooltip.Provider>
    </div>
  );
};

export default ListItem;
