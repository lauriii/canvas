import { useEffect, useMemo, useState } from 'react';
import parse from 'html-react-parser';
import { useParams } from 'react-router-dom';
import { PlusIcon } from '@radix-ui/react-icons';
import {
  AlertDialog,
  Badge,
  Box,
  Button,
  ContextMenu,
  Flex,
  HoverCard,
  Skeleton,
  Text,
  TextArea,
  TextField,
} from '@radix-ui/themes';

import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import EmptyStateCallout from '@/components/EmptyStateCallout';
import ErrorCard from '@/components/error/ErrorCard';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import UnifiedMenu from '@/components/UnifiedMenu';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import {
  buildCreateVariantPayload,
  buildDuplicateVariantPayload,
  getPageContentMarkerVersion,
  PAGE_VARIANT_ENTITY_TYPE,
  useCreatePageVariantMutation,
  useDeletePageVariantMutation,
  useGetDefaultPageVariantQuery,
  useGetPageVariantsQuery,
  useSetDefaultPageVariantMutation,
  useUpdatePageVariantMutation,
} from '@/services/pageVariants';

import type { PageVariant } from '@/types/PageVariant';

// Menu content shared by the per-variant dropdown and right-click context menu.
const VariantMenuContent = ({
  variant,
  isDefault,
  onEdit,
  onRename,
  onDuplicate,
  onSetDefault,
  onToggleStatus,
  onDelete,
}: {
  variant: PageVariant;
  isDefault: boolean;
  onEdit: (variant: PageVariant) => void;
  onRename: (variant: PageVariant) => void;
  onDuplicate: (variant: PageVariant) => void;
  onSetDefault: (variant: PageVariant) => void;
  onToggleStatus: (variant: PageVariant) => void;
  onDelete: (variant: PageVariant) => void;
}) => (
  <>
    <UnifiedMenu.Label>{variant.label || variant.id}</UnifiedMenu.Label>
    <UnifiedMenu.Separator />
    <UnifiedMenu.Item
      onClick={(event) => event.stopPropagation()}
      onSelect={() => onEdit(variant)}
    >
      Edit
    </UnifiedMenu.Item>
    <UnifiedMenu.Item
      onClick={(event) => event.stopPropagation()}
      onSelect={() => onRename(variant)}
    >
      Rename
    </UnifiedMenu.Item>
    <UnifiedMenu.Item
      onClick={(event) => event.stopPropagation()}
      onSelect={() => onDuplicate(variant)}
    >
      Duplicate
    </UnifiedMenu.Item>
    {!isDefault && variant.status && (
      <UnifiedMenu.Item
        onClick={(event) => event.stopPropagation()}
        onSelect={() => onSetDefault(variant)}
      >
        Set as default
      </UnifiedMenu.Item>
    )}
    {isDefault ? (
      // The server blocks disabling the site default variant, so surface why
      // rather than firing a request that will fail.
      <UnifiedMenu.Item
        color="gray"
        disabled
        onClick={(event) => event.stopPropagation()}
      >
        <HoverCard.Root>
          <HoverCard.Trigger onClick={(event) => event.stopPropagation()}>
            <Text as="span">Disable</Text>
          </HoverCard.Trigger>
          <HoverCard.Content>
            <Text as="p">
              Cannot disable the default template. Set another template as the
              default first.
            </Text>
          </HoverCard.Content>
        </HoverCard.Root>
      </UnifiedMenu.Item>
    ) : (
      <UnifiedMenu.Item
        onClick={(event) => event.stopPropagation()}
        onSelect={() => onToggleStatus(variant)}
      >
        {variant.status ? 'Disable' : 'Enable'}
      </UnifiedMenu.Item>
    )}
    <UnifiedMenu.Separator />
    {isDefault ? (
      // The server blocks deleting the site default variant, so surface why
      // rather than firing a request that will fail.
      <UnifiedMenu.Item
        color="gray"
        disabled
        onClick={(event) => event.stopPropagation()}
      >
        <HoverCard.Root>
          <HoverCard.Trigger onClick={(event) => event.stopPropagation()}>
            <Text as="span">Delete</Text>
          </HoverCard.Trigger>
          <HoverCard.Content>
            <Text as="p">
              Cannot delete the default template. Set another template as the
              default first.
            </Text>
          </HoverCard.Content>
        </HoverCard.Root>
      </UnifiedMenu.Item>
    ) : (
      <AlertDialog.Root>
        <AlertDialog.Trigger>
          <UnifiedMenu.Item
            color="red"
            onClick={(event) => event.stopPropagation()}
            onSelect={(event) => event.preventDefault()}
          >
            Delete
          </UnifiedMenu.Item>
        </AlertDialog.Trigger>
        <AlertDialog.Content>
          <AlertDialog.Title>
            Delete {variant.label || variant.id}
          </AlertDialog.Title>
          <AlertDialog.Description size="2">
            This action will permanently delete the page template. This action
            cannot be undone.
          </AlertDialog.Description>
          <Flex gap="3" mt="4" justify="end">
            <AlertDialog.Cancel>
              <Button variant="soft" color="gray">
                Cancel
              </Button>
            </AlertDialog.Cancel>
            <AlertDialog.Action>
              <Button
                variant="solid"
                color="red"
                onClick={() => onDelete(variant)}
              >
                Delete template
              </Button>
            </AlertDialog.Action>
          </Flex>
        </AlertDialog.Content>
      </AlertDialog.Root>
    )}
  </>
);

const VariantListItem = ({
  variant,
  isDefault,
  onEdit,
  onRename,
  onDuplicate,
  onSetDefault,
  onToggleStatus,
  onDelete,
}: {
  variant: PageVariant;
  isDefault: boolean;
  onEdit: (variant: PageVariant) => void;
  onRename: (variant: PageVariant) => void;
  onDuplicate: (variant: PageVariant) => void;
  onSetDefault: (variant: PageVariant) => void;
  onToggleStatus: (variant: PageVariant) => void;
  onDelete: (variant: PageVariant) => void;
}) => {
  const { urlForEditor } = useEditorNavigation();
  const { entityType, entityId } = useParams();
  const isBeingEdited =
    entityType === PAGE_VARIANT_ENTITY_TYPE && entityId === variant.id;
  const menuContent = (
    <VariantMenuContent
      variant={variant}
      isDefault={isDefault}
      onEdit={onEdit}
      onRename={onRename}
      onDuplicate={onDuplicate}
      onSetDefault={onSetDefault}
      onToggleStatus={onToggleStatus}
      onDelete={onDelete}
    />
  );

  return (
    <ContextMenu.Root>
      <ContextMenu.Trigger>
        <div data-testid={`canvas-page-variant-${variant.id}`}>
          <SidebarNode
            title={variant.label || variant.id}
            variant="template"
            selected={isBeingEdited}
            to={urlForEditor(PAGE_VARIANT_ENTITY_TYPE, variant.id)}
            trailingContent={
              // The default is always enabled, so the badges are mutually
              // exclusive.
              isDefault || !variant.status ? (
                <Badge
                  size="1"
                  variant="soft"
                  color={isDefault ? 'green' : 'gray'}
                  // On the active (solid) row the soft badge is illegible;
                  // use a translucent white chip instead.
                  style={
                    isBeingEdited
                      ? { background: 'rgb(255 255 255 / 20%)', color: '#fff' }
                      : undefined
                  }
                >
                  {isDefault ? 'Default' : 'Disabled'}
                </Badge>
              ) : undefined
            }
            dropdownMenuContent={
              <UnifiedMenu.Content menuType="dropdown">
                {menuContent}
              </UnifiedMenu.Content>
            }
          />
        </div>
      </ContextMenu.Trigger>
      <UnifiedMenu.Content menuType="context" align="start" side="right">
        {menuContent}
      </UnifiedMenu.Content>
    </ContextMenu.Root>
  );
};

interface VariantFormValues {
  label: string;
  description: string;
}

// Shared create / rename dialog. `onSubmit` receives the trimmed field values.
const VariantFormDialog = ({
  open,
  title,
  confirmText,
  initialValues,
  isSubmitting,
  isConfirmDisabled = false,
  error,
  onSubmit,
  onOpenChange,
}: {
  open: boolean;
  title: string;
  confirmText: string;
  initialValues: VariantFormValues;
  isSubmitting: boolean;
  isConfirmDisabled?: boolean;
  error?: string;
  onSubmit: (values: VariantFormValues) => void;
  onOpenChange: (open: boolean) => void;
}) => {
  const [label, setLabel] = useState(initialValues.label);
  const [description, setDescription] = useState(initialValues.description);

  // Reset the fields whenever the dialog is (re)opened for a new target.
  useEffect(() => {
    if (open) {
      setLabel(initialValues.label);
      setDescription(initialValues.description);
    }
  }, [open, initialValues.label, initialValues.description]);

  return (
    <Dialog
      open={open}
      title={title}
      onOpenChange={onOpenChange}
      error={
        error
          ? {
              title: 'Something went wrong',
              message: parse(error),
            }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText,
        isConfirmDisabled: isConfirmDisabled || !label.trim(),
        isConfirmLoading: isSubmitting,
        onConfirm: () =>
          onSubmit({ label: label.trim(), description: description.trim() }),
      }}
    >
      <Flex direction="column" gap="2" mb="2">
        <Box>
          <DialogFieldLabel htmlFor="page-variant-label">Name</DialogFieldLabel>
        </Box>
        <TextField.Root
          id="page-variant-label"
          data-testid="canvas-page-variant-label-input"
          value={label}
          placeholder="Template name"
          size="1"
          onChange={(event) => setLabel(event.target.value)}
        />
        <Box mt="2">
          <DialogFieldLabel htmlFor="page-variant-description">
            Description
          </DialogFieldLabel>
        </Box>
        <TextArea
          id="page-variant-description"
          data-testid="canvas-page-variant-description-input"
          value={description}
          placeholder="Optional description"
          size="1"
          onChange={(event) => setDescription(event.target.value)}
        />
      </Flex>
    </Dialog>
  );
};

const emptyFormValues: VariantFormValues = { label: '', description: '' };

const PageVariants = () => {
  const { navigateToEditor } = useEditorNavigation();
  const { data: variants, error: variantsError } = useGetPageVariantsQuery();
  const { data: defaultVariant } = useGetDefaultPageVariantQuery();
  const { data: components } = useGetComponentsQuery();

  const [
    createVariant,
    { isLoading: isCreating, error: createError, reset: resetCreate },
  ] = useCreatePageVariantMutation();
  const [
    updateVariant,
    { isLoading: isUpdating, error: updateError, reset: resetUpdate },
  ] = useUpdatePageVariantMutation();
  const [deleteVariant] = useDeletePageVariantMutation();
  const [setDefault] = useSetDefaultPageVariantMutation();

  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [renameTarget, setRenameTarget] = useState<PageVariant | null>(null);

  const variantList = useMemo(
    () => (variants ? Object.values(variants) : []),
    [variants],
  );
  const existingIds = useMemo(
    () => (variants ? Object.keys(variants) : []),
    [variants],
  );
  const markerVersion = getPageContentMarkerVersion(components);
  const defaultId = defaultVariant?.default_page_variant ?? null;

  const handleCreate = async ({ label, description }: VariantFormValues) => {
    if (!markerVersion) {
      return;
    }
    try {
      await createVariant(
        buildCreateVariantPayload({
          label,
          description,
          existingIds,
          markerVersion,
        }),
      ).unwrap();
      setIsCreateOpen(false);
    } catch {
      // The dialog surfaces `createError`; keep it open so the user can retry.
    }
  };

  const handleDuplicate = async (variant: PageVariant) => {
    try {
      await createVariant(
        buildDuplicateVariantPayload({ source: variant, existingIds }),
      ).unwrap();
    } catch {
      // Duplicating a valid variant rarely fails; a dedicated error surface for
      // it is a follow-up. The unwrapped rejection is intentionally ignored.
    }
  };

  const handleRename = async ({ label, description }: VariantFormValues) => {
    if (!renameTarget) {
      return;
    }
    try {
      await updateVariant({
        id: renameTarget.id,
        label,
        description,
      }).unwrap();
      setRenameTarget(null);
    } catch {
      // The dialog surfaces `updateError`; keep it open so the user can retry.
    }
  };

  return (
    <div data-testid="canvas-page-variants-panel">
      <Flex direction="column" mb="4">
        <Button
          data-testid="canvas-page-variant-new-button"
          variant="soft"
          size="1"
          disabled={!markerVersion}
          onClick={() => {
            resetCreate();
            setIsCreateOpen(true);
          }}
        >
          <PlusIcon />
          New page template
        </Button>
      </Flex>

      {/* Show the skeleton only until there is something to render (a list or an
          error). Deriving from the data/error, rather than the query's
          `isLoading` flag, keeps a remount that already has the variants cached
          from getting stuck on the skeleton. */}
      <Skeleton
        height="1.2rem"
        loading={!variants && !variantsError}
        width="100%"
        my="3"
      >
        <Box>
          {variantsError && (
            <ErrorCard
              title="An unexpected error has occurred while loading page templates."
              error={String(extractErrorMessageFromApiResponse(variantsError))}
            />
          )}
          {!variantsError && variantList.length === 0 && (
            <EmptyStateCallout
              data-testid="canvas-page-variant-list"
              title="No page templates found"
              variant="surface"
            />
          )}
          {!variantsError && variantList.length > 0 && (
            <Flex
              data-testid="canvas-page-variant-list"
              direction="column"
              gap="1"
            >
              {variantList.map((variant) => (
                <VariantListItem
                  key={variant.id}
                  variant={variant}
                  isDefault={variant.id === defaultId}
                  onEdit={(item) =>
                    navigateToEditor(PAGE_VARIANT_ENTITY_TYPE, item.id)
                  }
                  onRename={setRenameTarget}
                  onDuplicate={handleDuplicate}
                  onSetDefault={(item) => setDefault(item.id)}
                  onToggleStatus={(item) =>
                    updateVariant({ id: item.id, status: !item.status })
                  }
                  onDelete={(item) => deleteVariant(item.id)}
                />
              ))}
            </Flex>
          )}
        </Box>
      </Skeleton>

      {isCreateOpen && (
        <VariantFormDialog
          open={isCreateOpen}
          title="New page template"
          confirmText="Create template"
          initialValues={emptyFormValues}
          isSubmitting={isCreating}
          error={
            createError
              ? extractErrorMessageFromApiResponse(createError)
              : undefined
          }
          onSubmit={handleCreate}
          onOpenChange={setIsCreateOpen}
        />
      )}

      {renameTarget && (
        <VariantFormDialog
          open={!!renameTarget}
          title="Rename page template"
          confirmText="Save changes"
          initialValues={{
            label: renameTarget.label ?? '',
            description: renameTarget.description ?? '',
          }}
          isSubmitting={isUpdating}
          error={
            updateError
              ? extractErrorMessageFromApiResponse(updateError)
              : undefined
          }
          onSubmit={handleRename}
          onOpenChange={(open) => {
            if (!open) {
              resetUpdate();
              setRenameTarget(null);
            }
          }}
        />
      )}
    </div>
  );
};

export default PageVariants;
