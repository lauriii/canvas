import SidebarNode from '@/components/sidebar/SidebarNode';
import type React from 'react';
import { useEffect } from 'react';
import UnifiedMenu from '@/components/UnifiedMenu';
import { ContextMenu } from '@radix-ui/themes';
import styles from '@/features/code-editor/CodeComponentList.module.css';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  openDeleteDialog,
  openRenameDialog,
  openRemoveFromComponentsDialog,
  openInLayoutDialog,
} from '@/features/ui/codeComponentDialogSlice';
import { useGetCodeComponentQuery } from '@/services/componentAndLayout';
import type { CodeComponentSerialized } from '@/types/CodeComponent';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { componentExistsInLayout } from '@/features/layout/layoutUtils';
import { useErrorBoundary } from 'react-error-boundary';
import type { JSComponent } from '@/types/Component';
import { useNavigate } from 'react-router-dom';
import useXbParams from '@/hooks/useXbParams';

function removeJsPrefix(input: string): string {
  if (input.startsWith('js.')) {
    return input.substring(3);
  }
  return input;
}

const ExposedJsComponent: React.FC<{
  component: JSComponent;
  onMenuOpenChange?: (open: boolean) => void;
}> = (props) => {
  const dispatch = useAppDispatch();
  const { component, onMenuOpenChange } = props;
  const machineName = removeJsPrefix(component.id);
  const { data: jsComponent, error } = useGetCodeComponentQuery(machineName);
  const layout = useAppSelector(selectLayout);
  const isComponentInLayout = componentExistsInLayout(layout, component.id);
  const { showBoundary } = useErrorBoundary();
  const navigate = useNavigate();
  const { codeComponentId: selectedComponent } = useXbParams();

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  const handleRemoveFromComponentsClick = (
    e: React.MouseEvent<HTMLDivElement>,
  ) => {
    e.stopPropagation();
    if (isComponentInLayout) {
      dispatch(openInLayoutDialog());
    } else {
      dispatch(
        openRemoveFromComponentsDialog(jsComponent as CodeComponentSerialized),
      );
    }
  };

  const handleRenameClick = (e: React.MouseEvent<HTMLDivElement>) => {
    e.stopPropagation();
    dispatch(openRenameDialog(jsComponent as CodeComponentSerialized));
  };

  const handleDeleteClick = (e: React.MouseEvent<HTMLDivElement>) => {
    e.stopPropagation();
    if (isComponentInLayout) {
      dispatch(openInLayoutDialog());
    } else {
      dispatch(openDeleteDialog(jsComponent as CodeComponentSerialized));
    }
  };

  const handleEditClick = (e: React.MouseEvent<HTMLDivElement>) => {
    e.stopPropagation();
    navigate(`/code-editor/component/${machineName}`);
  };

  const menuItems = (
    <>
      <UnifiedMenu.Item onClick={handleRemoveFromComponentsClick}>
        Remove from components
      </UnifiedMenu.Item>
      <UnifiedMenu.Item onClick={handleEditClick}>Edit code</UnifiedMenu.Item>
      <UnifiedMenu.Item onClick={handleRenameClick}>Rename</UnifiedMenu.Item>
      <UnifiedMenu.Separator />
      <UnifiedMenu.Item color="red" onClick={handleDeleteClick}>
        Delete
      </UnifiedMenu.Item>
    </>
  );
  return (
    <ContextMenu.Root
      key={component.id}
      onOpenChange={(open) => {
        onMenuOpenChange?.(open);
      }}
    >
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
          selected={machineName === selectedComponent}
          onMenuOpenChange={onMenuOpenChange}
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
