import type React from 'react';
import type { XBComponent, JSComponent } from '@/types/Component';
import type { Section } from '@/types/Section';
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
import ExposedJsComponent from '@/components/list/ExposedJsComponent';
import useXbParams from '@/hooks/useXbParams';
import { DEFAULT_REGION } from '@/features/ui/uiSlice';
import SectionNode from '@/components/list/SectionNode';

const ListItem: React.FC<{
  item: XBComponent | Section;
  type: 'component' | 'section';
}> = (props) => {
  const { item, type } = props;
  const dispatch = useAppDispatch();
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const layout = useAppSelector(selectLayout);
  const [previewingComponent, setPreviewingComponent] = useState<
    XBComponent | Section
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
              component: item as XBComponent,
            },
            setSelectedComponent,
          ),
        );
      } else if (type === 'section') {
        dispatch(
          addNewSectionToLayout(
            {
              to: newPath,
              layoutModel: (item as Section).layoutModel,
            },
            setSelectedComponent,
          ),
        );
      }
    }
  };

  const handleMouseEnter = (component: XBComponent | Section) => {
    if (!isMenuOpen) {
      setPreviewingComponent(component);
    }
  };

  const renderItem = () => {
    if (type === 'section') {
      return (
        <SectionNode
          section={item as Section}
          onMenuOpenChange={setIsMenuOpen}
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
        />
      );
    }
    return (
      <SidebarNode
        title={item.name}
        variant={
          type === 'component' && (item as XBComponent).source === 'Blocks'
            ? 'blockComponent'
            : type
        }
      />
    );
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
          <Tooltip.Trigger style={{ width: '100%' }}>
            {renderItem()}
          </Tooltip.Trigger>
          <Tooltip.Portal>
            <Tooltip.Content
              side="right"
              sideOffset={24}
              align="start"
              className={styles.componentPreviewTooltipContent}
              onClick={(e) => e.stopPropagation()}
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
