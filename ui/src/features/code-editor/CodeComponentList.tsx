import type React from 'react';
import { useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useErrorBoundary } from 'react-error-boundary';
import { Callout, ContextMenu, Flex, Skeleton } from '@radix-ui/themes';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import UnifiedMenu from '@/components/UnifiedMenu';
import { useGetCodeComponentsQuery } from '@/services/componentAndLayout';
import {
  openDeleteDialog,
  openRenameDialog,
} from '@/features/ui/codeComponentDialogSlice';
import { useAppDispatch } from '@/app/hooks';
import type { CodeComponentSerialized } from '@/types/CodeComponent';
import styles from './CodeComponentList.module.css';
import { InfoCircledIcon } from '@radix-ui/react-icons';

const CodeComponentList = () => {
  const {
    data: codeComponents,
    error,
    isLoading,
  } = useGetCodeComponentsQuery({ status: false });
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

  if ((!codeComponents || !Object.keys(codeComponents).length) && !isLoading) {
    return (
      <Callout.Root size="1" variant="soft" color="gray" my="3">
        <Flex align="center" gapX="2">
          <Callout.Icon>
            <InfoCircledIcon />
          </Callout.Icon>
          <Callout.Text size="1">
            No items to show in Code components
          </Callout.Text>
        </Flex>
      </Callout.Root>
    );
  }

  return (
    <>
      <Skeleton loading={isLoading} height="1.2rem" width="100%" my="3">
        <Flex direction="column">
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
                  <UnifiedMenu.Item
                    onClick={(e: React.MouseEvent<HTMLDivElement>) => {
                      e.stopPropagation();
                      handleRenameClick(component);
                    }}
                  >
                    Rename
                  </UnifiedMenu.Item>
                  {/* @todo: Add this item back in https://drupal.org/i/3524274.}
                  {/*<UnifiedMenu.Item*/}
                  {/*  onClick={(e: React.MouseEvent<HTMLDivElement>) => {*/}
                  {/*    e.stopPropagation();*/}
                  {/*    handleAddToComponentsClick(component);*/}
                  {/*  }}*/}
                  {/*>*/}
                  {/*  Add to components*/}
                  {/*</UnifiedMenu.Item>*/}
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
              );

              return (
                <ContextMenu.Root key={id}>
                  <ContextMenu.Trigger>
                    <SidebarNode
                      title={component.name}
                      variant="code"
                      draggable={false}
                      onClick={() =>
                        handleComponentClick(component.machineName)
                      }
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
      </Skeleton>
      <Skeleton loading={isLoading} height="1.2rem" width="100%" my="3" />
      <Skeleton loading={isLoading} height="1.2rem" width="100%" my="3" />
    </>
  );
};

export default CodeComponentList;
