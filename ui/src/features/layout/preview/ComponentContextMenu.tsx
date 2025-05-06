import type React from 'react';
import type { ReactNode } from 'react';
import { useCallback, useEffect } from 'react';
import { ContextMenu } from '@radix-ui/themes';
import { UnifiedMenu } from '@/components/UnifiedMenu';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectCanvasViewPort,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import type { UnifiedMenuType } from '@/components/UnifiedMenu';
import type { ComponentNode } from '@/features/layout/layoutModelSlice';
import {
  deleteNode,
  duplicateNode,
  shiftNode,
} from '@/features/layout/layoutModelSlice';
import { setDialogOpen } from '@/features/ui/dialogSlice';
import useGetComponentName from '@/hooks/useGetComponentName';
import ComponentContextMenuRegions from '@/features/layout/preview/ComponentContextMenuRegions';
import useComponentSelection from '@/hooks/useComponentSelection';
import { selectSelectedComponentUuid } from '@/features/ui/uiSlice';
import useCopyPasteComponents from '@/hooks/useCopyPasteComponents';

interface ComponentContextMenuProps {
  children: ReactNode;
  component: ComponentNode;
}

export const ComponentContextMenuContent: React.FC<
  Pick<ComponentContextMenuProps, 'component'> & {
    menuType?: UnifiedMenuType;
  }
> = ({ component, menuType = 'context' }) => {
  const dispatch = useAppDispatch();
  const componentName = useGetComponentName(component);
  const canvasViewPort = useAppSelector(selectCanvasViewPort);
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const { setSelectedComponent, unsetSelectedComponent } =
    useComponentSelection();
  const componentUuid = component.uuid;
  const { copySelectedComponent, pasteAfterSelectedComponent } =
    useCopyPasteComponents();

  const handleDeleteClick = useCallback(
    (ev: React.MouseEvent<HTMLElement>) => {
      ev.stopPropagation();
      if (componentUuid) {
        dispatch(deleteNode(componentUuid));
        unsetSelectedComponent();
      }
      dispatch(unsetHoveredComponent());
    },
    [componentUuid, dispatch, unsetSelectedComponent],
  );

  const handleSelectClick = useCallback(
    (ev: React.MouseEvent<HTMLElement>) => {
      ev.stopPropagation();
      dispatch(unsetHoveredComponent());
      if (componentUuid) {
        setSelectedComponent(componentUuid);
      }
    },
    [dispatch, componentUuid, setSelectedComponent],
  );

  const handleDuplicateClick = useCallback(
    (ev: React.MouseEvent<HTMLElement>) => {
      ev.stopPropagation();
      dispatch(unsetHoveredComponent());

      if (componentUuid) {
        dispatch(duplicateNode({ uuid: componentUuid }));
      }
    },
    [dispatch, componentUuid],
  );

  const handleCopyClick = useCallback(
    (ev: React.MouseEvent<HTMLElement>) => {
      ev.stopPropagation();
      dispatch(unsetHoveredComponent());

      if (componentUuid) {
        copySelectedComponent(componentUuid);
      }
    },
    [dispatch, componentUuid, copySelectedComponent],
  );

  const handlePasteClick = useCallback(
    (ev: React.MouseEvent<HTMLElement>) => {
      ev.stopPropagation();
      dispatch(unsetHoveredComponent());

      if (componentUuid) {
        pasteAfterSelectedComponent(componentUuid);
      }
    },
    [dispatch, componentUuid, pasteAfterSelectedComponent],
  );

  const handleMoveUpClick = useCallback(
    (ev: React.MouseEvent<HTMLElement>) => {
      ev.stopPropagation();
      dispatch(unsetHoveredComponent());

      dispatch(shiftNode({ uuid: componentUuid, direction: 'up' }));
    },
    [dispatch, componentUuid],
  );

  const handleMoveDownClick = useCallback(
    (ev: React.MouseEvent<HTMLElement>) => {
      ev.stopPropagation();
      dispatch(unsetHoveredComponent());

      dispatch(shiftNode({ uuid: componentUuid, direction: 'down' }));
    },
    [dispatch, componentUuid],
  );

  const handleCreateSectionClick = useCallback(
    (e: React.MouseEvent<HTMLElement>) => {
      e.stopPropagation();
      if (componentUuid !== selectedComponent) {
        setSelectedComponent(componentUuid);
      }
      dispatch(setDialogOpen('saveAsSection'));
    },
    [componentUuid, dispatch, selectedComponent, setSelectedComponent],
  );

  const closeContextMenu = () => {
    // @todo https://www.drupal.org/i/3506657: There has to be a better way to close the context menu than firing an esc key press.
    const escapeEvent = new KeyboardEvent('keydown', {
      key: 'Escape',
      code: 'Escape',
      bubbles: true,
      cancelable: true,
    });
    document.dispatchEvent(escapeEvent);
  };

  useEffect(() => {
    // if the user zooms or pans, close the context menu.
    closeContextMenu();
  }, [canvasViewPort]);

  return (
    <UnifiedMenu.Content
      aria-label={`Context menu for ${componentName}`}
      menuType={menuType}
      align="start"
      side="right"
    >
      <UnifiedMenu.Label>{componentName}</UnifiedMenu.Label>
      <UnifiedMenu.Item onClick={handleSelectClick}>Edit</UnifiedMenu.Item>
      <UnifiedMenu.Item onClick={handleDuplicateClick} shortcut="⌘ D">
        Duplicate
      </UnifiedMenu.Item>
      <UnifiedMenu.Item onClick={handleCopyClick} shortcut="⌘ C">
        Copy
      </UnifiedMenu.Item>
      <UnifiedMenu.Item onClick={handlePasteClick} shortcut="⌘ V">
        Paste
      </UnifiedMenu.Item>
      <UnifiedMenu.Separator />
      <UnifiedMenu.Item onClick={handleCreateSectionClick}>
        Create section
      </UnifiedMenu.Item>
      <UnifiedMenu.Separator />

      <UnifiedMenu.Sub>
        <UnifiedMenu.SubTrigger>Move</UnifiedMenu.SubTrigger>
        <UnifiedMenu.SubContent>
          <UnifiedMenu.Item onClick={handleMoveUpClick}>
            Move up
          </UnifiedMenu.Item>
          <UnifiedMenu.Item onClick={handleMoveDownClick}>
            Move down
          </UnifiedMenu.Item>

          <UnifiedMenu.Separator />
          <UnifiedMenu.Item onClick={() => alert('Todo')}>
            Move into
          </UnifiedMenu.Item>
        </UnifiedMenu.SubContent>
      </UnifiedMenu.Sub>
      <ComponentContextMenuRegions component={component} />
      <UnifiedMenu.Separator />
      <UnifiedMenu.Item shortcut="⌫" color="red" onClick={handleDeleteClick}>
        Delete
      </UnifiedMenu.Item>
    </UnifiedMenu.Content>
  );
};

const ComponentContextMenu: React.FC<ComponentContextMenuProps> = ({
  children,
  component,
}) => {
  return (
    <ContextMenu.Root>
      <ContextMenu.Trigger>{children}</ContextMenu.Trigger>
      <ComponentContextMenuContent component={component} />
    </ContextMenu.Root>
  );
};

export default ComponentContextMenu;
