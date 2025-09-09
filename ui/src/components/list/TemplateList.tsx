import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { useErrorBoundary } from 'react-error-boundary';
import FolderIcon from '@assets/icons/folder.svg?react';
import NewTabIcon from '@assets/icons/new-tab.svg?react';
import * as Collapsible from '@radix-ui/react-collapsible';
import { ChevronRightIcon, DotsHorizontalIcon } from '@radix-ui/react-icons';
import {
  ContextMenu,
  DropdownMenu,
  Flex,
  Skeleton,
  Text,
} from '@radix-ui/themes';

import Dialog from '@/components/Dialog';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import UnifiedMenu from '@/components/UnifiedMenu';
import {
  useDeleteContentTemplateMutation,
  useGetContentTemplatesQuery,
} from '@/services/componentAndLayout';

import type {
  TemplateInBundle,
  TemplateViewMode,
} from '@/services/componentAndLayout';

import styles from '@/components/list/List.module.css';
import nodeStyles from '@/components/sidePanel/SidebarNode.module.css';

type BundleListItemProps = {
  bundle: TemplateInBundle;
};
const TemplateList = () => {
  const { showBoundary } = useErrorBoundary();

  const { data, isLoading, isFetching, error } = useGetContentTemplatesQuery();
  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  return (
    <Skeleton
      loading={isLoading || isFetching}
      height="1.2rem"
      width="100%"
      my="3"
    >
      {!!data?.node?.bundles &&
        Object.entries(data.node.bundles).map(([bundleKey, bundle]) => (
          <BundleListItem key={bundleKey} bundle={bundle} />
        ))}
    </Skeleton>
  );
};

const BundleListItem = ({ bundle }: BundleListItemProps) => {
  const [isOpen, setIsOpen] = useState(true);
  const menuItems = [];

  if (bundle.editFieldsUrl) {
    menuItems.push(
      <UnifiedMenu.Item
        key="edit-fields"
        onClick={() => window.open(bundle.editFieldsUrl, '_blank')}
      >
        Edit fields
        <Flex ml="auto" align="end">
          <NewTabIcon />
        </Flex>
      </UnifiedMenu.Item>,
    );
  }
  if (bundle.deleteUrl) {
    if (menuItems.length > 0) {
      menuItems.push(<UnifiedMenu.Separator key="pre-delete-separator" />);
    }

    menuItems.push(
      <UnifiedMenu.Item
        key="delete-bundle"
        color="red"
        onClick={() => window.open(bundle.deleteUrl, '_blank')}
      >
        Delete content type
        <Flex align="end">
          <NewTabIcon />
        </Flex>
      </UnifiedMenu.Item>,
    );
  }

  if (menuItems.length > 0) {
    menuItems.unshift(
      <UnifiedMenu.Item
        color="gray"
        key="bundle-label"
        className={styles.hoverInert}
      >
        {bundle.label}
      </UnifiedMenu.Item>,
    );
  }

  return (
    <Collapsible.Root open={isOpen} onOpenChange={setIsOpen}>
      <Collapsible.Trigger
        className={clsx(styles.folderTrigger)}
        data-canvas-folder-name={bundle.label}
      >
        <Flex
          className={clsx(nodeStyles.contextualAccordionVariant)}
          flexGrow="1"
          align="center"
          overflow="hidden"
          pb="2"
          pt="2"
        >
          <ContextMenu.Root key={bundle.label}>
            <ContextMenu.Trigger>
              <>
                <Flex pl="2" align="center" flexShrink="0">
                  <FolderIcon className={styles.folderIcon} />
                </Flex>
                <Flex px="2" align="center" flexGrow="1" overflow="hidden">
                  <Text size="1" weight="medium">
                    {bundle.label}
                  </Text>
                </Flex>
                <DropdownMenu.Root>
                  <DropdownMenu.Trigger>
                    <button
                      aria-label="Open contextual menu"
                      className={styles.contextualTrigger}
                    >
                      <span className={nodeStyles.dots}>
                        <DotsHorizontalIcon />
                      </span>
                    </button>
                  </DropdownMenu.Trigger>
                  <UnifiedMenu.Content menuType="dropdown">
                    {menuItems}
                  </UnifiedMenu.Content>
                </DropdownMenu.Root>
                <Flex pl="2" align="end" flexShrink="0">
                  <ChevronRightIcon
                    className={clsx(styles.chevron, {
                      [styles.isOpen]: isOpen,
                    })}
                  />
                </Flex>
              </>
            </ContextMenu.Trigger>
          </ContextMenu.Root>
        </Flex>
      </Collapsible.Trigger>
      <Collapsible.Content>
        <Flex pl="5" direction="column">
          {Object.entries(bundle.viewModes).map(([key, viewMode]) => (
            <TemplateListItem key={viewMode.id} viewMode={viewMode} />
          ))}
        </Flex>
      </Collapsible.Content>
    </Collapsible.Root>
  );
};

const TemplateListItem = ({ viewMode }: { viewMode: TemplateViewMode }) => {
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [
    deleteContentTemplate,
    { isLoading, error, isError, isSuccess, reset },
  ] = useDeleteContentTemplateMutation();

  const handleDelete = async () => {
    await deleteContentTemplate(viewMode.id);
  };

  const deleteDialog = (
    <Dialog
      onOpenChange={(open) => {}}
      open={deleteDialogOpen}
      title="Delete template"
      description={`Are you sure you want to delete "${viewMode.label}"? This action cannot be undone.`}
      error={
        isError
          ? {
              title: 'Failed to delete template',
              message: `An error ${
                'status' in error ? '(HTTP ' + error.status + ')' : ''
              } occurred while deleting the template. Please check the browser console for more details.`,
              resetButtonText: 'Try again',
              onReset: handleDelete,
            }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Delete',
        onConfirm: handleDelete,
        isConfirmDisabled: false,
        isConfirmLoading: isLoading,
        isDanger: true,
        onCancel: () => setDeleteDialogOpen(false),
      }}
    ></Dialog>
  );

  useEffect(() => {
    if (isSuccess) {
      setDeleteDialogOpen(false);
      reset();
    }
  }, [isSuccess, reset]);

  useEffect(() => {
    if (isError) {
      console.error('Failed to delete template:', error);
    }
  }, [isError, error]);

  return (
    <>
      <ContextMenu.Root key={viewMode.id}>
        <ContextMenu.Trigger>
          <SidebarNode
            title={`${viewMode.viewModeLabel} template`}
            variant="template"
            dropdownMenuContent={
              <UnifiedMenu.Content menuType="dropdown">
                <UnifiedMenu.Item
                  color="red"
                  onClick={() => {
                    setDeleteDialogOpen(true);
                  }}
                >
                  Delete template
                </UnifiedMenu.Item>
              </UnifiedMenu.Content>
            }
          />
        </ContextMenu.Trigger>
      </ContextMenu.Root>
      {deleteDialogOpen && deleteDialog}
    </>
  );
};

export default TemplateList;
