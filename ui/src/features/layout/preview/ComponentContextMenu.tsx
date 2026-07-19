import { useCallback, useEffect } from 'react';
import { ContextMenu } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import PermissionCheck from '@/components/PermissionCheck';
import { UnifiedMenu } from '@/components/UnifiedMenu';
import {
  deleteNode,
  duplicateNode,
  selectLayout,
  shiftNode,
} from '@/features/layout/layoutModelSlice';
import ComponentContextMenuMoveInto from '@/features/layout/preview/ComponentContextMenuMoveInto';
import ComponentContextMenuRegions from '@/features/layout/preview/ComponentContextMenuRegions';
import { setDialogOpen } from '@/features/ui/dialogSlice';
import {
  DEFAULT_REGION,
  selectEditorViewPortScale,
  selectSelectedComponentUuid,
  selectSelection,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import useCopyPasteComponents from '@/hooks/useCopyPasteComponents';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import useGetComponentName from '@/hooks/useGetComponentName';
import useMultiSelectionOperations from '@/hooks/useMultiSelectionOperations';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

import type React from 'react';
import type { ReactNode } from 'react';
import type { UnifiedMenuType } from '@/components/UnifiedMenu';
import type { ComponentNode } from '@/features/layout/layoutModelSlice';

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
  const layout = useAppSelector(selectLayout);
  const { data: components } = useGetComponentsQuery();
  const componentName = useGetComponentName(component);
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const hasGlobalRegions = layout.some((r) => r.id !== DEFAULT_REGION);
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const { setSelectedComponent, unsetSelectedComponent } =
    useComponentSelection();
  const componentUuid = component.uuid;
  const { copySelectedComponent, pasteAfterSelectedComponent } =
    useCopyPasteComponents();
  const { navigateToCodeEditor } = useEditorNavigation();
  const selection = useAppSelector(selectSelection);
  const {
    deleteSelection,
    copySelection,
    duplicateSelection,
    saveSelectionAsPattern,
  } = useMultiSelectionOperations();
  // When the clicked component is part of the current multi-selection, the
  // menu offers batch actions applied to the whole selection.
  const isSelectionMember =
    selection.items.length > 1 && selection.items.includes(componentUuid);

  // Check if this is a code component
  const [componentType] = (component.type || '').split('@');
  const isCodeComponent =
    componentType &&
    components &&
    components[componentType]?.source === 'Code component';

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

  const handleCreatePatternClick = useCallback(
    (e: React.MouseEvent<HTMLElement>) => {
      e.stopPropagation();
      if (componentUuid !== selectedComponent) {
        setSelectedComponent(componentUuid);
      }
      dispatch(setDialogOpen('saveAsPattern'));
    },
    [componentUuid, dispatch, selectedComponent, setSelectedComponent],
  );

  const handleEditCodeClick = useCallback(
    (ev: React.MouseEvent<HTMLElement>) => {
      ev.stopPropagation();
      if (component.type && component.type.startsWith('js.')) {
        const machineNameAndVersion = component.type.substring(3);
        const [machineName] = machineNameAndVersion.split('@');
        navigateToCodeEditor(machineName);
      }
    },
    [component.type, navigateToCodeEditor],
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
    // If the user zooms, close the context menu. Panning is no problem as the context menu prevents scrolling with the
    // mouse wheel, and it is closed automatically when panning via clicking the mouse.
    closeContextMenu();
  }, [editorViewPortScale]);

  const handleBatchAction = useCallback(
    (ev: React.MouseEvent<HTMLElement>, action: () => void) => {
      ev.stopPropagation();
      dispatch(unsetHoveredComponent());
      action();
    },
    [dispatch],
  );

  if (isSelectionMember) {
    const count = selection.items.length;
    // Single-only actions (move, paste after, edit code) are hidden here:
    // they have no defined meaning for a group.
    return (
      <UnifiedMenu.Content
        aria-label={`Context menu for ${count} selected items`}
        menuType={menuType}
        align="start"
        side="right"
      >
        <UnifiedMenu.Label>{count} items selected</UnifiedMenu.Label>
        <UnifiedMenu.Separator />
        <UnifiedMenu.Item
          disabled={!selection.consecutive}
          onClick={(ev) => handleBatchAction(ev, duplicateSelection)}
        >
          Duplicate {count} items
        </UnifiedMenu.Item>
        <UnifiedMenu.Item
          disabled={!selection.consecutive}
          onClick={(ev) => handleBatchAction(ev, copySelection)}
          shortcut="⌘ C"
        >
          Copy {count} items
        </UnifiedMenu.Item>
        <PermissionCheck hasPermission="patterns">
          <UnifiedMenu.Separator />
          <UnifiedMenu.Item
            disabled={!selection.consecutive}
            onClick={(ev) => handleBatchAction(ev, saveSelectionAsPattern)}
          >
            Create pattern from {count} items
          </UnifiedMenu.Item>
        </PermissionCheck>
        {!selection.consecutive && (
          <UnifiedMenu.Label>
            Actions are only available when selecting adjacent items
          </UnifiedMenu.Label>
        )}
        <UnifiedMenu.Separator />
        <UnifiedMenu.Item
          shortcut="⌫"
          color="red"
          onClick={(ev) => handleBatchAction(ev, deleteSelection)}
        >
          Delete {count} items
        </UnifiedMenu.Item>
      </UnifiedMenu.Content>
    );
  }

  return (
    <UnifiedMenu.Content
      aria-label={`Context menu for ${componentName}`}
      menuType={menuType}
      align="start"
      side="right"
    >
      <UnifiedMenu.Label>{componentName}</UnifiedMenu.Label>
      {isCodeComponent && (
        <PermissionCheck hasPermission="codeComponents">
          <UnifiedMenu.Item onClick={handleEditCodeClick}>
            Edit code
          </UnifiedMenu.Item>
        </PermissionCheck>
      )}
      <UnifiedMenu.Separator />

      <UnifiedMenu.Item onClick={handleDuplicateClick} shortcut="⌘ D">
        Duplicate
      </UnifiedMenu.Item>
      <UnifiedMenu.Item onClick={handleCopyClick} shortcut="⌘ C">
        Copy
      </UnifiedMenu.Item>
      <UnifiedMenu.Item onClick={handlePasteClick} shortcut="⌘ V">
        Paste
      </UnifiedMenu.Item>
      <PermissionCheck hasPermission="patterns">
        <UnifiedMenu.Separator />
        <UnifiedMenu.Item onClick={handleCreatePatternClick}>
          Create pattern
        </UnifiedMenu.Item>
      </PermissionCheck>
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
          {components && Object.keys(components).length > 0 && (
            <ComponentContextMenuMoveInto
              component={component}
              components={components}
            />
          )}
        </UnifiedMenu.SubContent>
      </UnifiedMenu.Sub>
      {hasGlobalRegions && (
        <PermissionCheck hasPermission="globalRegions">
          <ComponentContextMenuRegions component={component} />
        </PermissionCheck>
      )}
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
  const selection = useAppSelector(selectSelection);
  const { setSelectedComponent } = useComponentSelection();

  const handleOpenChange = useCallback(
    (open: boolean) => {
      // Opening the context menu on a component outside the current selection
      // replaces the selection with that component; opening it on a selection
      // member keeps the selection so batch actions apply to it.
      if (open && !selection.items.includes(component.uuid)) {
        setSelectedComponent(component.uuid);
      }
    },
    [component.uuid, selection.items, setSelectedComponent],
  );

  return (
    <ContextMenu.Root onOpenChange={handleOpenChange}>
      <ContextMenu.Trigger>{children}</ContextMenu.Trigger>
      <ComponentContextMenuContent component={component} />
    </ContextMenu.Root>
  );
};

export default ComponentContextMenu;
