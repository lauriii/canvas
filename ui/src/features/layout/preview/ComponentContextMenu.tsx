import type React from 'react';
import type { ReactNode } from 'react';
import { useCallback, useEffect } from 'react';
import { ContextMenu } from '@radix-ui/themes';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectCanvasViewPort,
  selectSelectedComponent,
  setSelectedComponent,
  unsetHoveredComponent,
  unsetSelectedComponent,
} from '@/features/ui/uiSlice';
import type { ComponentNode } from '@/features/layout/layoutModelSlice';
import {
  deleteNode,
  duplicateNode,
  shiftNode,
} from '@/features/layout/layoutModelSlice';
import { setDialogOpen } from '@/features/ui/dialogSlice';
import useGetComponentName from '@/hooks/useGetComponentName';

interface ComponentContextMenuProps {
  children: ReactNode;
  component: ComponentNode;
}

const ComponentContextMenu: React.FC<ComponentContextMenuProps> = (props) => {
  const { children, component } = props;
  const dispatch = useAppDispatch();
  const componentName = useGetComponentName(component);
  const canvasViewPort = useAppSelector(selectCanvasViewPort);
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const componentUuid = component.uuid;

  const handleDeleteClick = useCallback(() => {
    if (componentUuid) {
      dispatch(deleteNode(componentUuid));
      dispatch(unsetSelectedComponent());
    }
    dispatch(unsetHoveredComponent());
  }, [dispatch, componentUuid]);

  const handleSelectClick = useCallback(() => {
    dispatch(unsetHoveredComponent());
    if (componentUuid) {
      dispatch(setSelectedComponent(componentUuid));
    }
  }, [dispatch, componentUuid]);

  const handleDuplicateClick = useCallback(() => {
    dispatch(unsetHoveredComponent());

    if (componentUuid) {
      dispatch(duplicateNode({ uuid: componentUuid }));
    }
  }, [dispatch, componentUuid]);

  const handleMoveUpClick = useCallback(() => {
    dispatch(unsetHoveredComponent());

    dispatch(shiftNode({ uuid: componentUuid, direction: 'up' }));
  }, [dispatch, componentUuid]);

  const handleMoveDownClick = useCallback(() => {
    dispatch(unsetHoveredComponent());

    dispatch(shiftNode({ uuid: componentUuid, direction: 'down' }));
  }, [dispatch, componentUuid]);

  const handleCreateSectionClick = useCallback(
    (e: React.MouseEvent<HTMLElement>) => {
      e.stopPropagation();
      if (componentUuid !== selectedComponent) {
        dispatch(setSelectedComponent(componentUuid));
      }
      dispatch(setDialogOpen('saveAsSection'));
    },
    [componentUuid, dispatch, selectedComponent],
  );

  const closeContextMenu = () => {
    // Todo: There has to be a better way to close the context menu than firing an esc key press.
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
    <ContextMenu.Root>
      <ContextMenu.Trigger>{children}</ContextMenu.Trigger>
      <ContextMenu.Content aria-label={`Context menu for ${componentName}`}>
        <ContextMenu.Label>{componentName}</ContextMenu.Label>
        <ContextMenu.Item onClick={handleSelectClick}>Edit</ContextMenu.Item>
        <ContextMenu.Item onClick={handleDuplicateClick} shortcut="⌘ D">
          Duplicate
        </ContextMenu.Item>
        <ContextMenu.Item onClick={handleDuplicateClick} shortcut="⌘ C">
          Copy
        </ContextMenu.Item>
        <ContextMenu.Item onClick={handleDuplicateClick} shortcut="⌘ V">
          Paste
        </ContextMenu.Item>
        <ContextMenu.Separator />
        <ContextMenu.Item onClick={handleCreateSectionClick}>
          Create section
        </ContextMenu.Item>
        <ContextMenu.Separator />

        <ContextMenu.Sub>
          <ContextMenu.SubTrigger>Move</ContextMenu.SubTrigger>
          <ContextMenu.SubContent>
            <ContextMenu.Item onClick={handleMoveUpClick}>
              Move up
            </ContextMenu.Item>
            <ContextMenu.Item onClick={handleMoveDownClick}>
              Move down
            </ContextMenu.Item>

            <ContextMenu.Separator />
            <ContextMenu.Item onClick={() => alert('Todo')}>
              Move into
            </ContextMenu.Item>
          </ContextMenu.SubContent>
        </ContextMenu.Sub>
        <ContextMenu.Separator />
        <ContextMenu.Item shortcut="⌫" color="red" onClick={handleDeleteClick}>
          Delete
        </ContextMenu.Item>
      </ContextMenu.Content>
    </ContextMenu.Root>
  );
};

export default ComponentContextMenu;
