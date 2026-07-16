import { useState } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';
import { useDraggable } from '@dnd-kit/core';
import * as Tooltip from '@radix-ui/react-tooltip';
import { Theme } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ComponentPreview from '@/components/ComponentPreview';
import CodeComponentItem from '@/components/list/CodeComponentItem';
import ComponentItem from '@/components/list/ComponentItem';
import PatternItem from '@/components/list/PatternItem';
import UnifiedMenu from '@/components/UnifiedMenu';
import {
  findExposedSlotEntry,
  isLockedSlotRegion,
} from '@/features/layout/exposedSlots';
import {
  _addNewComponentToLayout,
  addNewPatternToLayout,
  selectExposedSlots,
  selectLayout,
  selectSlotDefaults,
  selectSlotOverrides,
} from '@/features/layout/layoutModelSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { selectActivePanel } from '@/features/ui/primaryPanelSlice';
import { DEFAULT_REGION, selectSelection } from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';

import type React from 'react';
import type { LayoutItemType } from '@/features/ui/primaryPanelSlice';
import type { CodeComponentSerialized } from '@/types/CodeComponent';
import type { CanvasComponent, JSComponent } from '@/types/Component';
import type { Pattern } from '@/types/Pattern';

import styles from '@/components/list/List.module.css';

export type LibraryItem = CanvasComponent | Pattern | CodeComponentSerialized;

// Does this item support preview thumbnail rendering? (requires default_markup)
function supportsPreview(item: LibraryItem): item is CanvasComponent | Pattern {
  return 'default_markup' in item && !!item.default_markup;
}

// Checks the source to see if this component is a JS (code) component.
function isJSComponent(item: LibraryItem): item is JSComponent {
  return 'source' in item && (item as JSComponent).source === 'Code component';
}

const ListItem: React.FC<{
  item: LibraryItem;
  type:
    | LayoutItemType.COMPONENT
    | LayoutItemType.PATTERN
    | LayoutItemType.DYNAMIC
    | LayoutItemType.CODE;
}> = (props) => {
  const { item, type } = props;
  const itemId = 'id' in item ? item.id : item.machineName;
  const dispatch = useAppDispatch();
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const layout = useAppSelector(selectLayout);
  const [previewingComponent, setPreviewingComponent] = useState<
    CanvasComponent | Pattern
  >();
  const {
    componentId: selectedComponent,
    regionId: focusedRegion = DEFAULT_REGION,
  } = useParams();
  const { setSelectedComponent } = useComponentSelection();
  const activePanel = useAppSelector(selectActivePanel);
  const selection = useAppSelector(selectSelection);
  const exposedSlots = useAppSelector(selectExposedSlots);
  const slotOverrides = useAppSelector(selectSlotOverrides);
  const slotDefaults = useAppSelector(selectSlotDefaults);

  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
    id: itemId,
    data: {
      origin: activePanel,
      type,
      item: item,
      name: item.name,
    },
  });

  // Disable drag for broken components
  const isDraggable = () => ('broken' in item ? !item.broken : true);

  const handleInsertClick = (e: React.MouseEvent<HTMLDivElement>) => {
    e.stopPropagation();
    let path: number[] | null = [0];
    if (selectedComponent) {
      path = findNodePathByUuid(layout, selectedComponent);
    } else if (selection.items.length === 1) {
      // A non-routed selection: a slot's `${hostUuid}/${slotName}` (template
      // editor slot node, or a per-content exposed slot).
      const selectedId = selection.items[0];
      const [hostUuid, slotName] = selectedId.split('/');
      const exposedEntry = slotName
        ? findExposedSlotEntry(exposedSlots, hostUuid, slotName)
        : null;
      // A locked slot rejects content changes until unlocked.
      if (
        exposedEntry &&
        isLockedSlotRegion(
          exposedEntry.alias,
          exposedSlots,
          slotOverrides,
          slotDefaults,
        )
      ) {
        return;
      }
      const nodePath = findNodePathByUuid(layout, selectedId);
      if (nodePath) {
        // A slot node: insert into it (the +1 below lands on index 0).
        path = slotName ? [...nodePath, -1] : nodePath;
      } else if (exposedEntry) {
        // Per-content: the exposed slot presents as a top-level region keyed
        // by its backing field alias.
        const regionIndex = layout.findIndex(
          (region) => region.id === exposedEntry.alias,
        );
        path = regionIndex === -1 ? null : [regionIndex, -1];
      } else {
        path = null;
      }
    } else if (focusedRegion) {
      let regionIndex = layout.findIndex(
        (region) => region.id === focusedRegion,
      );
      if (regionIndex === -1) {
        // Per-content layouts have no DEFAULT_REGION: target the first
        // editable (non-locked) slot region instead.
        regionIndex = layout.findIndex(
          (region) =>
            !isLockedSlotRegion(
              region.id,
              exposedSlots,
              slotOverrides,
              slotDefaults,
            ),
        );
      }
      path = regionIndex === -1 ? null : [regionIndex, 0];
    }
    if (path) {
      const newPath = [...path];
      newPath[newPath.length - 1] += 1;

      if (type === 'component' || type === 'dynamicComponent') {
        dispatch(
          _addNewComponentToLayout(
            {
              to: newPath,
              component: item as CanvasComponent,
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

  const handleMouseEnter = (maybePreviewItem: LibraryItem) => {
    if (!isMenuOpen && supportsPreview(maybePreviewItem)) {
      setPreviewingComponent(maybePreviewItem);
    }
  };

  const insertMenuItem = () => (
    <UnifiedMenu.Item onClick={handleInsertClick}>Insert</UnifiedMenu.Item>
  );

  const menuTitleItems = () => (
    <>
      <UnifiedMenu.Label>{item.name}</UnifiedMenu.Label>
      <UnifiedMenu.Separator />
    </>
  );

  const renderItem = () => {
    if (type === 'pattern') {
      return (
        <PatternItem
          pattern={item as Pattern}
          onMenuOpenChange={setIsMenuOpen}
          disabled={isDragging}
          insertMenuItem={insertMenuItem()}
          menuTitleItems={menuTitleItems()}
        />
      );
    }

    // Code (exposed or not) being rendered in the code tab.
    if (type === 'code') {
      return (
        <CodeComponentItem
          component={item as CodeComponentSerialized}
          exposed={(item as CodeComponentSerialized).status}
          onMenuOpenChange={setIsMenuOpen}
          disabled={isDragging}
          insertMenuItem={insertMenuItem()}
          menuTitleItems={menuTitleItems()}
        />
      );
    }

    // Exposed JS component being rendered in the Library tab.
    if (type === 'component' && isJSComponent(item)) {
      return (
        <CodeComponentItem
          component={item as JSComponent}
          exposed={true}
          onMenuOpenChange={setIsMenuOpen}
          disabled={isDragging}
          insertMenuItem={insertMenuItem()}
          menuTitleItems={menuTitleItems()}
        />
      );
    }

    // Generic component item
    if (type === 'component' || type === 'dynamicComponent') {
      return (
        <ComponentItem
          component={item as CanvasComponent}
          onMenuOpenChange={setIsMenuOpen}
          disabled={isDragging}
          insertMenuItem={insertMenuItem()}
          menuTitleItems={menuTitleItems()}
        ></ComponentItem>
      );
    }
    return null;
  };

  let wrapperProps: React.HTMLAttributes<HTMLDivElement> &
    React.RefAttributes<HTMLDivElement> & {
      'data-canvas-component-id': string;
      'data-canvas-name': string;
      'data-canvas-type':
        | LayoutItemType.PATTERN
        | LayoutItemType.COMPONENT
        | LayoutItemType.DYNAMIC
        | LayoutItemType.CODE;
    } = {
    role: 'listitem',
    'data-canvas-component-id': itemId,
    'data-canvas-name': item.name,
    'data-canvas-type': type,
    className: clsx(styles.listItem),
  };

  wrapperProps = {
    ...wrapperProps,
    onMouseEnter: () => handleMouseEnter(item),
  };
  if (isDraggable()) {
    wrapperProps = {
      ...attributes,
      ...wrapperProps,
      ...listeners,
      ref: setNodeRef,
    };
  }

  return (
    <div key={itemId} {...wrapperProps}>
      <Tooltip.Provider>
        <Tooltip.Root delayDuration={0}>
          <Tooltip.Trigger asChild={true} style={{ width: '100%' }}>
            <div>{renderItem()}</div>
          </Tooltip.Trigger>
          {supportsPreview(item) && (
            <Tooltip.Portal>
              <Tooltip.Content
                side="right"
                sideOffset={24}
                align="start"
                className={styles.componentPreviewTooltipContent}
                onClick={(e) => e.stopPropagation()}
                style={{ pointerEvents: 'none' }}
                aria-label={`${item.name} preview thumbnail`}
              >
                <Theme>
                  {previewingComponent && !isMenuOpen && (
                    <ComponentPreview componentListItem={previewingComponent} />
                  )}
                </Theme>
              </Tooltip.Content>
            </Tooltip.Portal>
          )}
        </Tooltip.Root>
      </Tooltip.Provider>
    </div>
  );
};

export default ListItem;
