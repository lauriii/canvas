import type { ComponentListItem } from '@/components/list/List';
import SidebarNode from '@/components/sidebar/SidebarNode';
import type React from 'react';
import { useEffect } from 'react';
import UnifiedMenu from '@/components/UnifiedMenu';
import { ContextMenu } from '@radix-ui/themes';
import styles from '@/features/code-editor/CodeComponentList.module.css';
import { handleNonWorkingBtn } from '@/utils/function-utils';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  openDeleteDialog,
  openRenameDialog,
  openRemoveFromComponentsDialog,
  openInLayoutDialog,
} from '@/features/ui/codeComponentDialogSlice';
import { useGetCodeComponentQuery } from '@/services/componentAndLayout';
import type { CodeComponent } from '@/types/CodeComponent';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { componentExistsInLayout } from '@/features/layout/layoutUtils';
import { useErrorBoundary } from 'react-error-boundary';

function removeJsPrefix(input: string): string {
  if (input.startsWith('js.')) {
    return input.substring(3);
  }
  return input;
}

const ExposedJsComponent: React.FC<{ component: ComponentListItem }> = (
  props,
) => {
  const dispatch = useAppDispatch();
  const { component } = props;
  const machineName = removeJsPrefix(component.id);
  const { data: jsComponent, error } = useGetCodeComponentQuery(machineName);
  const layout = useAppSelector(selectLayout);
  const isComponentInLayout = componentExistsInLayout(layout, component.id);
  const { showBoundary } = useErrorBoundary();

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  const handleRemoveFromComponentsClick = () => {
    if (isComponentInLayout) {
      dispatch(openInLayoutDialog());
    } else {
      dispatch(openRemoveFromComponentsDialog(jsComponent as CodeComponent));
    }
  };

  const handleRenameClick = () => {
    dispatch(openRenameDialog(jsComponent as CodeComponent));
  };

  const handleDeleteClick = () => {
    if (isComponentInLayout) {
      dispatch(openInLayoutDialog());
    } else {
      dispatch(openDeleteDialog(jsComponent as CodeComponent));
    }
  };

  const menuItems = (
    <>
      <UnifiedMenu.Item onClick={handleRemoveFromComponentsClick}>
        Remove from components
      </UnifiedMenu.Item>
      <UnifiedMenu.Item onClick={handleNonWorkingBtn}>
        Edit code
      </UnifiedMenu.Item>
      <UnifiedMenu.Item onClick={handleRenameClick}>Rename</UnifiedMenu.Item>
      <UnifiedMenu.Separator />
      <UnifiedMenu.Item color="red" onClick={handleDeleteClick}>
        Delete
      </UnifiedMenu.Item>
    </>
  );
  return (
    <ContextMenu.Root key={component.id}>
      <ContextMenu.Trigger>
        <SidebarNode
          title={component.name}
          variant="component"
          className={styles.listItem}
          dropdownMenuContent={
            <UnifiedMenu.Content menuType="dropdown">
              {menuItems}
            </UnifiedMenu.Content>
          }
        />
      </ContextMenu.Trigger>
      <UnifiedMenu.Content
        onClick={(e) => e.stopPropagation()}
        menuType="context"
        align="start"
        side="right"
      >
        {menuItems}
      </UnifiedMenu.Content>
    </ContextMenu.Root>
  );
};

export default ExposedJsComponent;
