import { useParams } from 'react-router-dom';
import { ContextMenu, HoverCard, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import PermissionCheck from '@/components/PermissionCheck';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import UnifiedMenu from '@/components/UnifiedMenu';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { componentExistsInLayout } from '@/features/layout/layoutUtils';
import {
  openDeleteDialog,
  openInLayoutDialog,
  openRemoveFromComponentsDialog,
  openRenameDialog,
} from '@/features/ui/codeComponentDialogSlice';
import { selectActivePanel } from '@/features/ui/primaryPanelSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';

import type { CodeComponentSerialized } from '@/types/CodeComponent';
import type { JSComponent } from '@/types/Component';

export function removeJsPrefix(input: string): string {
  if (input.startsWith('js.')) {
    return input.substring(3);
  }
  return input;
}

// Helper to get the correct id for a component
export function getComponentId(
  component: JSComponent | CodeComponentSerialized,
): string {
  // JSComponent has id, CodeComponentSerialized has machineName
  return (component as any).id || (component as any).machineName;
}

interface CodeComponentItemProps {
  component: JSComponent | CodeComponentSerialized;
  exposed: boolean;
  onMenuOpenChange?: (open: boolean) => void;
  disabled?: boolean;
  insertMenuItem?: React.ReactNode;
  menuTitleItems?: React.ReactNode;
}

const CodeComponentItem: React.FC<CodeComponentItemProps> = ({
  component,
  exposed,
  onMenuOpenChange = () => {},
  disabled = false,
  insertMenuItem,
  menuTitleItems,
}) => {
  const dispatch = useAppDispatch();
  const componentId = getComponentId(component);
  const machineName = removeJsPrefix(componentId);
  const layout = useAppSelector(selectLayout);
  const isComponentInLayout = componentExistsInLayout(layout, componentId);
  const { navigateToCodeEditor, urlForCodeEditor } = useEditorNavigation();
  const { codeComponentId: selectedComponent } = useParams();
  const activePanel = useAppSelector(selectActivePanel);
  const isExternal = component.type === 'external';

  // Menu item handlers
  const handleRemoveFromComponentsClick = (
    e: React.MouseEvent<HTMLDivElement>,
  ) => {
    e.stopPropagation();
    if (isComponentInLayout) {
      dispatch(openInLayoutDialog());
    } else {
      dispatch(
        openRemoveFromComponentsDialog(component as CodeComponentSerialized),
      );
    }
  };
  const handleRenameClick = (e: React.MouseEvent<HTMLDivElement>) => {
    e.stopPropagation();
    dispatch(openRenameDialog(component as CodeComponentSerialized));
  };
  const handleDeleteClick = (e: React.MouseEvent<HTMLDivElement>) => {
    e.stopPropagation();
    if (isComponentInLayout) {
      dispatch(openInLayoutDialog());
    } else {
      dispatch(openDeleteDialog(component as CodeComponentSerialized));
    }
  };
  const handleEditClick = (e: React.MouseEvent<HTMLDivElement>) => {
    e.stopPropagation();
    navigateToCodeEditor(machineName);
  };

  // Menu items for exposed code components. External components offer no
  // management actions: the external application owns their implementation,
  // name, and status, and the synchronization would revert any change.
  const exposedMenuItems = (
    <>
      {activePanel === 'library' && insertMenuItem}
      {isExternal ? (
        activePanel !== 'library' && (
          <UnifiedMenu.Item disabled>
            {Drupal.t('No actions available')}
          </UnifiedMenu.Item>
        )
      ) : (
        <PermissionCheck
          hasPermission="codeComponents"
          denied={
            activePanel !== 'library' && (
              <UnifiedMenu.Item disabled>
                {Drupal.t('No actions available')}
              </UnifiedMenu.Item>
            )
          }
        >
          <UnifiedMenu.Item onClick={handleRemoveFromComponentsClick}>
            {Drupal.t('Remove from components')}
          </UnifiedMenu.Item>
          <UnifiedMenu.Item onClick={handleEditClick}>
            {Drupal.t('Edit code')}
          </UnifiedMenu.Item>
          <UnifiedMenu.Item onClick={handleRenameClick}>
            {Drupal.t('Rename')}
          </UnifiedMenu.Item>
        </PermissionCheck>
      )}
    </>
  );

  // Menu items for non-exposed code components
  const nonExposedMenuItems = (
    <PermissionCheck
      hasPermission="codeComponents"
      denied={
        <UnifiedMenu.Item disabled>
          {Drupal.t('No actions available')}
        </UnifiedMenu.Item>
      }
    >
      {!isExternal && (
        <UnifiedMenu.Item onClick={handleEditClick}>
          {Drupal.t('Edit code')}
        </UnifiedMenu.Item>
      )}
      {!isExternal && (
        <UnifiedMenu.Item onClick={handleRenameClick}>
          {Drupal.t('Rename')}
        </UnifiedMenu.Item>
      )}
      {/* @todo: Add this item back in https://drupal.org/i/3524274.}
      {/* <UnifiedMenu.Item*/}
      {/*  onClick={(e: React.MouseEvent<HTMLDivElement>) => {*/}
      {/*    e.stopPropagation();*/}
      {/*    handleAddToComponentsClick(component);*/}
      {/*  }}*/}
      {/*>*/}
      {/*  Add to components*/}
      {/*</UnifiedMenu.Item>*/}
      <UnifiedMenu.Separator />
      {/* If the delete form is present, the component is safe to delete. */}
      {'links' in component && component?.links?.['delete-form'] ? (
        <UnifiedMenu.Item color="red" onClick={handleDeleteClick}>
          {Drupal.t('Delete')}
        </UnifiedMenu.Item>
      ) : (
        <UnifiedMenu.Item color="gray" disabled={true}>
          <HoverCard.Root>
            <HoverCard.Trigger onClick={(e) => e.stopPropagation()}>
              <Text as="span">{Drupal.t('Delete')}</Text>
            </HoverCard.Trigger>
            <HoverCard.Content>
              <Text as="p">
                {Drupal.t('Cannot delete components that are being used.')}
              </Text>
            </HoverCard.Content>
          </HoverCard.Root>
        </UnifiedMenu.Item>
      )}
    </PermissionCheck>
  );

  // Choose menu content based on 'exposed'
  const menuContent = exposed ? exposedMenuItems : nonExposedMenuItems;

  return (
    <ContextMenu.Root key={componentId} onOpenChange={onMenuOpenChange}>
      <ContextMenu.Trigger>
        <SidebarNode
          key={componentId}
          title={component.name}
          variant={exposed ? 'component' : 'code'}
          disabled={disabled}
          dropdownMenuContent={
            <UnifiedMenu.Content menuType="dropdown">
              {menuTitleItems}
              {menuContent}
            </UnifiedMenu.Content>
          }
          selected={machineName === selectedComponent}
          onMenuOpenChange={onMenuOpenChange}
          draggable={true}
          to={
            activePanel === 'code' && !isExternal
              ? urlForCodeEditor(machineName)
              : undefined
          }
        />
      </ContextMenu.Trigger>
      <UnifiedMenu.Content menuType="context" align="start" side="right">
        {menuTitleItems}
        {menuContent}
      </UnifiedMenu.Content>
    </ContextMenu.Root>
  );
};

export default CodeComponentItem;
