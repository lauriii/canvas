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
  addNewComponentToLayout,
  addNewSectionToLayout,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ComponentPreview from '@/components/ComponentPreview';
import SidebarNode from '@/components/sidebar/SidebarNode';
import { useNavigationUtils } from '@/hooks/useNavigationUtils';
import useXbParams from '@/hooks/useXbParams';
import { DEFAULT_REGION } from '@/features/ui/uiSlice';

const ListItem: React.FC<{
  item: ComponentListItem | SectionListItem;
  type: 'component' | 'section';
}> = (props) => {
  const { item, type } = props;
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const [previewingComponent, setPreviewingComponent] = useState<
    ComponentListItem | SectionListItem
  >();
  const {
    componentId: selectedComponent,
    regionId: focusedRegion = DEFAULT_REGION,
  } = useXbParams();
  const { setSelectedComponent } = useNavigationUtils();

  const clickToInsertHandler = (newId: string) => {
    let path: number[] | null = [0];
    if (selectedComponent) {
      path = findNodePathByUuid(layout, selectedComponent);
    } else if (focusedRegion) {
      path = [layout.findIndex((region) => region.id === focusedRegion), 0];
    }
    if (path) {
      const newPath = [...path];
      newPath[newPath.length - 1] += 1;

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
      className={clsx(styles.listItem)}
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
