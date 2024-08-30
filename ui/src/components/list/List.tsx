import { useEffect, useRef, useCallback, useState } from 'react';
import styles from './List.module.css';
import menuStyles from '@/components/topbar/add/AddMenu.module.css';
import { selectDragging, setListDragging } from '@/features/ui/uiSlice';
import {
  disableClickToInsert,
  NodeType,
  selectClickToInsertState,
  setHidden,
  setInactive,
} from '@/features/ui/addMenuSlice';
import Sortable from 'sortablejs';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useGetComponentsQuery } from '@/services/components';
import { Box, Flex, Spinner, Text } from '@radix-ui/themes';
import { customSortableDragImage } from '@/features/sortable/sortableUtils';
import clsx from 'clsx';
import * as Menubar from '@radix-ui/react-menubar';
import {
  addNewComponentToLayout,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import * as Tooltip from '@radix-ui/react-tooltip';
import type { Component } from '@/types/Component';
import ShadowWrapper from '../ShadowWrapper';

interface PreviewCache {
  [key: string]: string;
}

const List = () => {
  const dispatch = useAppDispatch();
  const { data: components, error, isLoading } = useGetComponentsQuery();
  const sortableInstance = useRef<Sortable | null>(null);
  const listElRef = useRef<HTMLDivElement>(null);
  const previewMarkupRef = useRef<PreviewCache>({});
  const { isDragging } = useAppSelector(selectDragging);
  const { isEnabled, originUUID, originNodeType } = useAppSelector(
    selectClickToInsertState,
  );
  const layout = useAppSelector(selectLayout);
  const componentsRef = useRef(components);
  const [previewContent, setPreviewContent] = useState<string>('');
  const defaultPreviewHeight = 800;
  const defaultPreviewWidth = 600;

  const handleDragStart = useCallback(() => {
    dispatch(setListDragging(true));
    // When dragging a component, hide it instead of closing the menu, because we don't want
    // to unmount the draggable element from the DOM.
    dispatch(setHidden(true));
  }, [dispatch]);

  const handleDragClone = useCallback((ev: Sortable.SortableEvent) => {
    ev.clone.dataset.isNew = 'true';
  }, []);

  const handleDragEnd = useCallback(() => {
    dispatch(setListDragging(false));
    // After the drag ends, we can now close the menu without disrupting
    // the draggable functionality. Setting the primary menu to an empty string
    // closes it.
    dispatch(setInactive());
  }, [dispatch]);

  const clickToInsertHandler = (newNodeId: string) => {
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
        dispatch(
          addNewComponentToLayout({
            to: newPath,
            newNode: newNodeId,
            component: componentsRef.current?.[newNodeId],
          }),
        );

        dispatch(disableClickToInsert());
        // Close the menu once the user selects section/component.
        dispatch(setInactive());
      }
    }
  };

  const handleMouseEnter = (component: Component) => {
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

  useEffect(() => {
    if (
      !isLoading &&
      listElRef.current !== null &&
      !sortableInstance.current?.el
    ) {
      sortableInstance.current = Sortable.create(listElRef.current, {
        dataIdAttr: 'data-xb-uuid',
        sort: false,
        group: {
          name: 'list',
          pull: 'clone',
          put: false,
          revertClone: true,
        },
        animation: 0,
        delay: 200,
        delayOnTouchOnly: true,
        ghostClass: styles.sortableGhost,
        onStart: handleDragStart,
        onEnd: handleDragEnd,
        onClone: handleDragClone,
      });
    }
    return () => {
      if (sortableInstance.current !== null) {
        sortableInstance.current.destroy();
      }
    };
  }, [isLoading, handleDragStart, handleDragEnd, handleDragClone]);

  return (
    <div className={clsx('listContainer', styles.listContainer)}>
      <Menubar.Label className={menuStyles.MenubarLabel}>Basic</Menubar.Label>
      <Box pt="2" className={isDragging ? 'list-dragging' : ''}>
        <Spinner loading={isLoading}>
          <Flex direction="column" width="100%" ref={listElRef}>
            {/*
         TODO: I've not figured out how to make this work as a UL/LI list as dragging LI elements into the preview doesn't work
         as an LI being dropped into a DIV is invalid and breaks the sortable newDraggableIndex value
        */}

            {error && (
              <div className="error">
                {
                  // @ts-ignore
                  error?.error
                }
              </div>
            )}
            {components &&
              Object.values(components).map((component, index) => (
                <div
                  key={component.id}
                  data-xb-uuid={component.id}
                  data-xb-name={component.name}
                  className={clsx(
                    'listItem',
                    styles.listItem,
                    menuStyles.MenubarItem,
                    // Highlight the first item in the list when click to insert is enabled to
                    // let the user know that they can click to insert the component.
                    index === 0 && isEnabled ? styles.outline : null,
                  )}
                  onClick={() => clickToInsertHandler(component.id)}
                  onDragStart={(event) =>
                    customSortableDragImage(
                      event,
                      window.document,
                      component.name,
                    )
                  }
                  onMouseEnter={() => handleMouseEnter(component)}
                >
                  <Tooltip.Provider>
                    <Tooltip.Root>
                      <Tooltip.Trigger
                        className={clsx(
                          'ComponentPreviewTrigger',
                          styles.ComponentPreviewTrigger,
                        )}
                      >
                        <Text>{component.name}</Text>
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
              ))}
          </Flex>
        </Spinner>
      </Box>
    </div>
  );
};

export default List;
