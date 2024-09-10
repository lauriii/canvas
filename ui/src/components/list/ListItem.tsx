import type React from 'react';
import type {
  ComponentListItem,
  SectionListItem,
} from '@/components/list/List';
import { useRef, useState } from 'react';
import clsx from 'clsx';
import styles from '@/components/list/List.module.css';
import menuStyles from '@/components/topbar/add/AddMenu.module.css';
import { customSortableDragImage } from '@/features/sortable/sortableUtils';
import * as Tooltip from '@radix-ui/react-tooltip';
import { Text } from '@radix-ui/themes';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import {
  disableClickToInsert,
  NodeType,
  selectClickToInsertState,
  setInactive,
} from '@/features/ui/addMenuSlice';
import {
  addNewComponentToLayout,
  addNewSectionToLayout,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ShadowWrapper from '@/components/ShadowWrapper';

interface PreviewCache {
  [key: string]: string;
}

const ListItem: React.FC<{
  item: ComponentListItem | SectionListItem;
  type: 'component' | 'section';
}> = (props) => {
  const { item, type } = props;

  const previewMarkupRef = useRef<PreviewCache>({});
  const dispatch = useAppDispatch();
  const { isEnabled, originUUID, originNodeType } = useAppSelector(
    selectClickToInsertState,
  );
  const layout = useAppSelector(selectLayout);

  const [previewContent, setPreviewContent] = useState<string>('');
  const defaultPreviewHeight = 800;
  const defaultPreviewWidth = 600;

  const clickToInsertHandler = (newId: string) => {
    if (isEnabled) {
      const path = findNodePathByUuid(layout, originUUID);
      if (path) {
        const newPath = [...path];
        // First check if the selected node is a component (node with parent) or
        // a section (node without parent).
        if (originNodeType === NodeType.COMPONENT) {
          // Example: A path [0, 2] means a node is the 3rd child of the 1st parent.
          // So when a new node is added, it should be added as the 4th child of the 1st parent.
          // Therefore, the newly inserted node's path would be [0, 3].
          newPath[newPath.length - 1] += 1;
        } else {
          // If the selected node is in the root level, then the new node should
          // be added as a sibling.
          // Example: A path [2] means a node is the 3rd root level node.
          // Therefore, the newly inserted node's path should be [3].
          newPath[0] += 1;
        }

        if (type === 'component') {
          dispatch(
            addNewComponentToLayout({
              to: newPath,
              newNode: newId,
              component: item as ComponentListItem,
            }),
          );
        } else if (type === 'section') {
          dispatch(
            addNewSectionToLayout({
              to: newPath,
              newSection: newId,
              layoutModel: (item as SectionListItem).layoutModel,
            }),
          );
        }

        dispatch(disableClickToInsert());
        // Close the menu once the user selects section/component.
        dispatch(setInactive());
      }
    }
  };

  const handleMouseEnter = (component: ComponentListItem | SectionListItem) => {
    // Use cached preview markup when available.
    if (previewMarkupRef.current[component.name]) {
      setPreviewContent(previewMarkupRef.current[component.name]);
      return;
    }

    // Unless the user is on a very narrow viewport (which isn't
    // ideal for working in XB), the component preview popup
    // will not be as wide as the layout it should appear in.
    // This is a good thing because the preview should not
    // cover large portions of the layout.
    // However, if the preview is generated based on the popup
    // width, it will render as if presented on a narrow
    // viewport. To mitigate this, we generate the preview based
    // on larger "calc" dimensions, then scale that content down
    // to fit the popup dimensions.
    const calcWidth = '1200';
    const calcHeight = '1600';

    const scaledPreview = document.createElement('div');
    Object.assign(scaledPreview.style, {
      width: `${calcWidth}px`,
      height: `${calcHeight}px`,
      visibility: 'hidden',
      position: 'absolute',
    });
    // Wrap the preview in a common parent so that can be used
    // to get height/width of the full component.
    scaledPreview.innerHTML = `<div data-common-parent>${component['default_markup']}</div>`;

    // Append to body so the element has dimensions.
    document.body.appendChild(scaledPreview);
    const { offsetWidth, offsetHeight } = scaledPreview
      .children[0] as HTMLElement;
    // If the previewed component is smaller than both preview
    // dimensions, reduce the container width and height
    // to match the component dimensions.
    if (
      offsetWidth < defaultPreviewWidth &&
      offsetHeight < defaultPreviewHeight
    ) {
      scaledPreview.style.width = `${offsetWidth}px`;
      scaledPreview.style.height = `${offsetHeight}px`;
    } else {
      // If we are here, then one or more component dimensions
      // exceed the preview maximums. We begin by determining
      // how much each dimension exceeds their maximum.
      const widthScale = defaultPreviewWidth / offsetWidth;
      const heightScale = defaultPreviewHeight / offsetHeight;

      Object.assign(scaledPreview.style, {
        // Scale the preview to whichever dimension requires the
        // most reduction to fit the preview container.
        transform: `scale(${Math.min(widthScale, heightScale)})`,
        transformOrigin: '0 0',
        // When width needs the most reduction, explicitly set
        // it, then set height to auto.
        width:
          widthScale < heightScale
            ? `${scaledPreview.offsetWidth * widthScale}px`
            : 'auto',
        // When height needs the most reduction, explicitly set
        // it, then set width to auto.
        height:
          widthScale > heightScale
            ? `${scaledPreview.offsetHeight * heightScale}px`
            : 'auto',
      });
    }

    // Create another wrapper that exists in its actual size
    // to contain the scaled-down div.
    const previewWrapper = document.createElement('div');
    previewWrapper.style.zIndex = '1';
    previewWrapper.append(scaledPreview);

    // Apply display styles and remove the visibility and
    // position styling that were only needed for dimension
    // assessment.
    Object.assign(scaledPreview.style, {
      overflow: 'hidden',
      'border-radius': '16px',
      'box-shadow': '4px 5px 13px 2px rgba(0,0,0,0.4)',
      visibility: null,
      position: null,
    });

    // Cache the preview markup in a ref.
    previewMarkupRef.current[component.name] = previewWrapper.outerHTML;
    setPreviewContent(previewWrapper.outerHTML);
  };

  return (
    <div
      key={item.id}
      data-xb-component-id={item.id}
      data-xb-name={item.name}
      data-xb-type={type}
      className={clsx(
        'listItem',
        styles.listItem,
        menuStyles.MenubarItem,
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
          <Tooltip.Trigger
            asChild
            className={clsx(
              'ComponentPreviewTrigger',
              styles.ComponentPreviewTrigger,
            )}
          >
            <Text>{item.name}</Text>
          </Tooltip.Trigger>
          <Tooltip.Portal>
            <Tooltip.Content
              side="right"
              className={clsx(
                'ComponentPreviewContent',
                styles.ComponentPreviewContent,
              )}
            >
              {previewContent && (
                <ShadowWrapper>
                  <div
                    dangerouslySetInnerHTML={{
                      __html: previewContent,
                    }}
                  />
                </ShadowWrapper>
              )}
            </Tooltip.Content>
          </Tooltip.Portal>
        </Tooltip.Root>
      </Tooltip.Provider>
    </div>
  );
};

export default ListItem;
