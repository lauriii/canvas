import { useEffect, useState } from 'react';
import NewTabIcon from '@assets/icons/new-tab.svg?react';
import { ContextMenu, Flex } from '@radix-ui/themes';

import Dialog from '@/components/Dialog';
import SidebarFolder from '@/components/sidePanel/SidebarFolder';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import UnifiedMenu from '@/components/UnifiedMenu';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useGetEditedTemplateId } from '@/hooks/useGetEditedTemplateId';
import { useSmartRedirect } from '@/hooks/useSmartRedirect';
import {
  useDeleteContentTemplateMutation,
  useUpdateContentTemplateMutation,
} from '@/services/componentAndLayout';
import { useGetPageVariantsQuery } from '@/services/pageVariants';
import { getCanvasSettings } from '@/utils/drupal-globals';
import { hasPermission } from '@/utils/permissions';

import type {
  TemplateInBundle,
  TemplatesInBundle,
  TemplateViewMode,
} from '@/services/componentAndLayout';

import nodeStyles from '@/components/sidePanel/SidebarNode.module.css';

type TemplateListProps = {
  entityTypeId: string;
  // The bundles of one entity type, as returned by the content templates list.
  bundles: TemplatesInBundle;
};

type BundleListItemProps = {
  entityTypeId: string;
  bundleKey: string;
  bundle: TemplateInBundle;
};

const TemplateList = ({ entityTypeId, bundles }: TemplateListProps) => (
  <>
    {Object.entries(bundles)
      .filter(([, bundle]) => Object.keys(bundle.viewModes).length > 0)
      .map(([bundleKey, bundle]) => (
        <BundleListItem
          key={bundleKey}
          entityTypeId={entityTypeId}
          bundleKey={bundleKey}
          bundle={bundle}
        />
      ))}
  </>
);

const BundleListItem = ({
  entityTypeId,
  bundleKey,
  bundle,
}: BundleListItemProps) => {
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
    // Only bundles that are config entities have a delete URL, so their entity
    // type has a bundle label, such as "Content type" or "Vocabulary".
    const bundleLabel =
      getCanvasSettings()?.contentTemplateEntityTypes?.[entityTypeId]
        ?.bundleLabel;
    if (menuItems.length > 0) {
      menuItems.push(<UnifiedMenu.Separator key="pre-delete-separator" />);
    }

    menuItems.push(
      <UnifiedMenu.Item
        key="delete-bundle"
        color="red"
        onClick={() => window.open(bundle.deleteUrl, '_blank')}
      >
        {bundleLabel ? `Delete ${bundleLabel.toLowerCase()}` : 'Delete'}
        <Flex align="end">
          <NewTabIcon />
        </Flex>
      </UnifiedMenu.Item>,
    );
  }

  if (menuItems.length > 0) {
    menuItems.unshift(<UnifiedMenu.Separator key="bundle-label-separator" />);
    menuItems.unshift(
      <UnifiedMenu.Label key="bundle-label">{bundle.label}</UnifiedMenu.Label>,
    );
  }

  const listItems = (
    <Flex pl="0" direction="column">
      {Object.entries(bundle.viewModes).map(([key, viewMode]) => (
        <TemplateListItem key={`${viewMode.id}-${key}`} viewMode={viewMode} />
      ))}
    </Flex>
  );

  if (!bundle.label) {
    return listItems;
  }

  return (
    <SidebarFolder
      // Bundle labels are not unique across entity types.
      id={`${entityTypeId}.${bundleKey}`}
      name={bundle.label}
      menuItems={menuItems.length ? menuItems : undefined}
      isOpen={isOpen}
      onOpenChange={setIsOpen}
      className={nodeStyles.contextualAccordionVariant}
    >
      {listItems}
    </SidebarFolder>
  );
};

const TemplateListItem = ({ viewMode }: { viewMode: TemplateViewMode }) => {
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [deleteContentTemplate, { isLoading, error, isError, reset }] =
    useDeleteContentTemplateMutation();
  const [updateContentTemplate] = useUpdateContentTemplateMutation();
  // Same flag the Templates panel gates its "Page templates" section on.
  // Without it the variants query returns 403, the radio group would render
  // only "Site default" (hiding the current selection), and clicking it would
  // silently clear the template's assigned variant.
  const canEditVariants = hasPermission('pageVariants');
  const canSelectVariant = canEditVariants && viewMode.viewMode === 'full';
  const { data: pageVariants } = useGetPageVariantsQuery(undefined, {
    skip: !canSelectVariant,
  });
  const selectedTemplateId = useGetEditedTemplateId();
  const { redirectToNextBestPage } = useSmartRedirect();
  const { urlForTemplateEditor } = useEditorNavigation();

  const handleDelete = async () => {
    try {
      const result = await deleteContentTemplate(viewMode.id);

      // If the mutation completed without error, treat it as success.
      // We handle this synchronously because the component unmounts when the
      // query re-fetches (after cache invalidation), preventing useEffect from
      // running.
      if (result && !result.error) {
        setDeleteDialogOpen(false);
        reset();
        redirectToNextBestPage();
      }
    } catch (error) {
      console.error('Failed to delete template:', error);
    }
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
    if (isError) {
      console.error('Failed to delete template:', error);
    }
  }, [isError, error]);

  const menuItems = (
    <>
      <UnifiedMenu.Label>{viewMode.viewModeLabel}</UnifiedMenu.Label>
      <UnifiedMenu.Separator />
      {canSelectVariant && (
        <>
          <UnifiedMenu.Sub>
            <UnifiedMenu.SubTrigger>Page template</UnifiedMenu.SubTrigger>
            <UnifiedMenu.SubContent>
              <UnifiedMenu.RadioGroup
                value={viewMode.pageVariant ?? ''}
                onValueChange={(value) =>
                  updateContentTemplate({
                    id: viewMode.id,
                    pageVariant: value || null,
                  })
                }
              >
                <UnifiedMenu.RadioItem value="">
                  Site default
                </UnifiedMenu.RadioItem>
                {Object.values(pageVariants ?? {})
                  // Disabled templates keep rendering where already selected,
                  // but cannot be selected anew.
                  .filter(
                    (variant) =>
                      variant.status || variant.id === viewMode.pageVariant,
                  )
                  .map((variant) => (
                    <UnifiedMenu.RadioItem key={variant.id} value={variant.id}>
                      {variant.label || variant.id}
                    </UnifiedMenu.RadioItem>
                  ))}
              </UnifiedMenu.RadioGroup>
            </UnifiedMenu.SubContent>
          </UnifiedMenu.Sub>
          <UnifiedMenu.Separator />
        </>
      )}
      <UnifiedMenu.Item
        color="red"
        onClick={() => {
          setDeleteDialogOpen(true);
        }}
      >
        Delete template
      </UnifiedMenu.Item>
    </>
  );

  return (
    <>
      <ContextMenu.Root key={viewMode.id}>
        <ContextMenu.Trigger>
          <SidebarNode
            key={viewMode.id}
            title={`${viewMode.viewModeLabel} template`}
            variant="template"
            dropdownMenuContent={
              <UnifiedMenu.Content menuType="dropdown">
                {menuItems}
              </UnifiedMenu.Content>
            }
            selected={selectedTemplateId === viewMode.id}
            indent={2.5}
            to={urlForTemplateEditor(viewMode)}
            data-testid={`template-list-item-${viewMode.bundle}-${viewMode.viewModeLabel}`}
          />
        </ContextMenu.Trigger>
        <UnifiedMenu.Content menuType="context" align="start" side="right">
          {menuItems}
        </UnifiedMenu.Content>
      </ContextMenu.Root>
      {deleteDialogOpen && deleteDialog}
    </>
  );
};

export default TemplateList;
