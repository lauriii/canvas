import { useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useErrorBoundary } from 'react-error-boundary';
import { ContextMenu, Flex, Spinner } from '@radix-ui/themes';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import UnifiedMenu from '@/components/UnifiedMenu';
import { useGetCodeComponentsQuery } from '@/services/componentAndLayout';
import {
  openDeleteDialog,
  openRenameDialog,
  openAddToComponentsDialog,
} from '@/features/ui/codeComponentDialogSlice';
import { useAppDispatch } from '@/app/hooks';
import type { CodeComponentSerialized } from '@/types/CodeComponent';
import styles from './CodeComponentList.module.css';

const CodeComponentList = ({
  type = 'code',
}: {
  type?: 'code' | 'override';
}) => {
  const {
    data: codeComponents,
    error,
    isLoading,
  } = useGetCodeComponentsQuery(
    type !== 'override'
      ? { status: false } // Internal code components.
      : {
          override: true,
          status: true, // Overrides need to be exposed to be taken into account.
        },
  );
  const dispatch = useAppDispatch();
  const { showBoundary } = useErrorBoundary();
  const navigate = useNavigate();
  const { codeComponentId: componentId } = useParams();

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  const handleComponentClick = (machineName: string) => {
    navigate(`/code-editor/code/${machineName}`);
  };

  const handleRenameClick = (component: CodeComponentSerialized) => {
    dispatch(openRenameDialog(component));
  };

  const handleDeleteClick = (component: CodeComponentSerialized) => {
    dispatch(openDeleteDialog(component));
  };

  const handleAddToComponentsClick = (component: CodeComponentSerialized) => {
    dispatch(openAddToComponentsDialog(component));
  };

  return (
    <Spinner loading={isLoading}>
      <Flex direction="column" minHeight="var(--space-6)">
        {codeComponents &&
          Object.entries(codeComponents).map(([id, component]) => {
            const menuItems = (
              <>
                <UnifiedMenu.Item
                  onClick={(e: React.MouseEvent<HTMLDivElement>) => {
                    e.stopPropagation();
                    handleComponentClick(component.machineName);
                  }}
                >
                  Edit
                </UnifiedMenu.Item>
                {type !== 'override' && (
                  <>
                    <UnifiedMenu.Item
                      onClick={(e: React.MouseEvent<HTMLDivElement>) => {
                        e.stopPropagation();
                        handleRenameClick(component);
                      }}
                    >
                      Rename
                    </UnifiedMenu.Item>
                    <UnifiedMenu.Item
                      onClick={(e: React.MouseEvent<HTMLDivElement>) => {
                        e.stopPropagation();
                        handleAddToComponentsClick(component);
                      }}
                    >
                      Add to components
                    </UnifiedMenu.Item>
                    <UnifiedMenu.Separator />
                    <UnifiedMenu.Item
                      color="red"
                      onClick={(e: React.MouseEvent<HTMLDivElement>) => {
                        e.stopPropagation();
                        handleDeleteClick(component);
                      }}
                    >
                      Delete
                    </UnifiedMenu.Item>
                  </>
                )}
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
