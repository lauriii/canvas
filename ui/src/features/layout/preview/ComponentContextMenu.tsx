import { useEffect } from 'react';
import { ContextMenu } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import PermissionCheck from '@/components/PermissionCheck';
import { UnifiedMenu } from '@/components/UnifiedMenu';
import ComponentContextMenuMoveInto from '@/features/layout/preview/ComponentContextMenuMoveInto';
import { selectEditorViewPortScale } from '@/features/ui/uiSlice';
import useComponentActions from '@/hooks/useComponentActions';
import useGetComponentName from '@/hooks/useGetComponentName';

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
  const componentName = useGetComponentName(component);
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const {
    components,
    isCodeComponent,
    isExternal,
    isMarker,
    deleteComponent: handleDeleteClick,
    duplicateComponent: handleDuplicateClick,
    copyComponent: handleCopyClick,
    pasteAfterComponent: handlePasteClick,
    moveUp: handleMoveUpClick,
    moveDown: handleMoveDownClick,
    createPattern: handleCreatePatternClick,
    editCode: handleEditCodeClick,
  } = useComponentActions(component);

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

  return (
    <UnifiedMenu.Content
      aria-label={`Context menu for ${componentName}`}
      menuType={menuType}
      align="start"
      side="right"
    >
      <UnifiedMenu.Label>{componentName}</UnifiedMenu.Label>
      {isCodeComponent && !isExternal && (
        <PermissionCheck hasPermission="codeComponents">
          <UnifiedMenu.Item onClick={handleEditCodeClick}>
            Edit code
          </UnifiedMenu.Item>
        </PermissionCheck>
      )}
      <UnifiedMenu.Separator />

      {!isMarker && (
        <UnifiedMenu.Item onClick={handleDuplicateClick} shortcut="⌘ D">
          Duplicate
        </UnifiedMenu.Item>
      )}
      {!isMarker && (
        <UnifiedMenu.Item onClick={handleCopyClick} shortcut="⌘ C">
          Copy
        </UnifiedMenu.Item>
      )}
      <UnifiedMenu.Item onClick={handlePasteClick} shortcut="⌘ V">
        Paste
      </UnifiedMenu.Item>
      {!isMarker && (
        <PermissionCheck hasPermission="patterns">
          <UnifiedMenu.Separator />
          <UnifiedMenu.Item onClick={handleCreatePatternClick}>
            Create pattern
          </UnifiedMenu.Item>
        </PermissionCheck>
      )}
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
      {!isMarker && (
        <>
          <UnifiedMenu.Separator />
          <UnifiedMenu.Item
            shortcut="⌫"
            color="red"
            onClick={handleDeleteClick}
          >
            Delete
          </UnifiedMenu.Item>
        </>
      )}
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
