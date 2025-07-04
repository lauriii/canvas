import type React from 'react';
import type { XBComponent, JSComponent } from '@/types/Component';
import type { Pattern } from '@/types/Pattern';
import { useState } from 'react';
import clsx from 'clsx';
import styles from '@/components/list/List.module.css';
import * as Tooltip from '@radix-ui/react-tooltip';
import { Theme } from '@radix-ui/themes';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import {
  addNewComponentToLayout,
  addNewPatternToLayout,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ComponentPreview from '@/components/ComponentPreview';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import useComponentSelection from '@/hooks/useComponentSelection';
import ExposedJsComponent from '@/components/list/ExposedJsComponent';
import { DEFAULT_REGION } from '@/features/ui/uiSlice';
import { useDraggable } from '@dnd-kit/core';
import PatternNode from '@/components/list/PatternNode';
import { useParams } from 'react-router';
import type { LayoutItemType } from '@/features/ui/primaryPanelSlice';

const ListItem: React.FC<{
  item: XBComponent | Pattern;
  type:
    | LayoutItemType.COMPONENT
    | LayoutItemType.PATTERN
    | LayoutItemType.DYNAMIC;
}> = (props) => {
  const { item, type } = props;
  const dispatch = useAppDispatch();
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const layout = useAppSelector(selectLayout);
  const [previewingComponent, setPreviewingComponent] = useState<
    XBComponent | Pattern
  >();
  const {
    componentId: selectedComponent,
    regionId: focusedRegion = DEFAULT_REGION,
  } = useParams();
  const { setSelectedComponent } = useComponentSelection();
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
    id: item.id,
    data: {
      origin: 'library',
      type,
      item: item,
      name: item.name,
    },
  });

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

      if (type === 'component' || type === 'dynamicComponent') {
        dispatch(
          addNewComponentToLayout(
            {
              to: newPath,
              component: item as XBComponent,
            },
            setSelectedComponent,
          ),
        );
      } else if (type === 'pattern') {
        dispatch(
          addNewPatternToLayout(
            {
              to: newPath,
              layoutModel: (item as Pattern).layoutModel,
            },
            setSelectedComponent,
          ),
        );
      }
    }
  };

  const handleMouseEnter = (component: XBComponent | Pattern) => {
    if (!isMenuOpen) {
      setPreviewingComponent(component);
    }
  };

  const renderItem = () => {
    if (type === 'pattern') {
      return (
        <PatternNode
          pattern={item as Pattern}
          onMenuOpenChange={setIsMenuOpen}
          disabled={isDragging}
        />
      );
    }
    if (
      type === 'component' &&
      (item as JSComponent).source === 'Code component'
    ) {
      return (
        <ExposedJsComponent
          component={item as JSComponent}
          onMenuOpenChange={setIsMenuOpen}
          disabled={isDragging}
        />
      );
    }
    return (
      <SidebarNode
        title={item.name}
        disabled={isDragging}
        variant={
          type === 'component' && (item as XBComponent).source === 'Blocks'
            ? 'dynamicComponent'
            : type
        }
      />
    );
  };

  return (
    <div
      key={item.id}
      {...attributes}
      {...listeners}
      role="listitem"
      ref={setNodeRef}
      data-xb-component-id={item.id}
      data-xb-name={item.name}
      data-xb-type={type}
      className={clsx(styles.listItem)}
      onClick={() => clickToInsertHandler(item.id)}
      onMouseEnter={() => handleMouseEnter(item)}
    >
      <Tooltip.Provider>
        <Tooltip.Root delayDuration={0}>
          <Tooltip.Trigger asChild={true} style={{ width: '100%' }}>
            <div>{renderItem()}</div>
          </Tooltip.Trigger>
          <Tooltip.Portal>
            <Tooltip.Content
              side="right"
              sideOffset={24}
              align="start"
              className={styles.componentPreviewTooltipContent}
              onClick={(e) => e.stopPropagation()}
              style={{ pointerEvents: 'none' }}
            >
              <Theme>
                {previewingComponent && !isMenuOpen && (
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
