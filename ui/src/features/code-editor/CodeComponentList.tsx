import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import useXbParams from '@/hooks/useXbParams';
import { useErrorBoundary } from 'react-error-boundary';
import { ContextMenu, Flex, Spinner } from '@radix-ui/themes';
import SidebarNode from '@/components/sidebar/SidebarNode';
import UnifiedMenu from '@/components/UnifiedMenu';
import { useGetCodeComponentsQuery } from '@/services/codeComponents';
import {
  openDeleteDialog,
  openRenameDialog,
} from '@/features/ui/codeComponentDialogSlice';
import { useAppDispatch } from '@/app/hooks';
import type { CodeComponent } from '@/types/CodeComponent';
import styles from './CodeComponentList.module.css';

const CodeComponentList = () => {
  const {
    data: codeComponents,
    error,
    isLoading,
  } = useGetCodeComponentsQuery();
  const dispatch = useAppDispatch();
  const { showBoundary } = useErrorBoundary();
  const navigate = useNavigate();
  const { componentId } = useXbParams();

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  const handleComponentClick = (machineName: string) => {
    navigate(`/code-editor/code/${machineName}`);
  };

  const handleRenameClick = (component: CodeComponent) => {
    dispatch(openRenameDialog(component));
  };

  const handleDeleteClick = (component: CodeComponent) => {
    dispatch(openDeleteDialog(component));
  };

  return (
    <Spinner loading={isLoading}>
      <Flex direction="column" minHeight="var(--space-6)">
        {codeComponents &&
          Object.entries(codeComponents).map(([id, component]) => {
            const menuItems = (
              <>
                <UnifiedMenu.Item
                  onClick={() => handleComponentClick(component.machineName)}
                >
                  Edit
                </UnifiedMenu.Item>
                <UnifiedMenu.Item onClick={() => handleRenameClick(component)}>
                  Rename
                </UnifiedMenu.Item>
                <UnifiedMenu.Separator />
                <UnifiedMenu.Item
                  color="red"
                  onClick={() => handleDeleteClick(component)}
                >
                  Delete
                </UnifiedMenu.Item>
              </>
            );

            return (
              <ContextMenu.Root key={id}>
                <ContextMenu.Trigger>
                  <SidebarNode
                    title={component.name}
                    variant="code"
                    onClick={() => handleComponentClick(component.machineName)}
                    className={styles.listItem}
                    selected={component.machineName === componentId}
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
          })}
      </Flex>
    </Spinner>
  );
};

export default CodeComponentList;
