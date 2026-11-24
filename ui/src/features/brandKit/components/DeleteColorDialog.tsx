import { TrashIcon } from '@radix-ui/react-icons';
import { Box, Flex, Spinner, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import {
  selectDialogOpen,
  setDialogWithDataClosed,
} from '@/features/ui/dialogSlice';
import {
  useDeleteColorMutation,
  useGetColorUsageDetailsQuery,
} from '@/services/brandKit';
import { componentAndLayoutApi } from '@/services/componentAndLayout';

import styles from './DeleteColorDialog.module.css';

const DeleteColorDialog = () => {
  const { deleteColorConfirm } = useAppSelector(selectDialogOpen);
  const { open: isDeleteDialogOpen, data: color } = deleteColorConfirm;
  const colorName = color.name ?? '';
  const colorId = color.id ?? '';

  const dispatch = useAppDispatch();
  const [deleteColor, { isLoading: isDeleting, isError, error, reset }] =
    useDeleteColorMutation();

  const {
    data: usageDetails,
    isLoading: isUsageLoading,
    isError: isUsageError,
  } = useGetColorUsageDetailsQuery(colorId, { skip: !isDeleteDialogOpen });

  const currentUsages = usageDetails?.current ?? [];
  const configUsages = usageDetails?.config ?? [];
  const priorUsages = usageDetails?.prior ?? [];

  // Whether the color can be deleted is decided by the server: it also weighs
  // auto-saves and default revisions, which the usage lists do not report.
  const isBlocked =
    !isUsageLoading &&
    usageDetails !== undefined &&
    usageDetails.deletable === false;
  const isDeleteDisabled = isUsageLoading || isUsageError || isBlocked;

  const hasPriorOnlyWarning =
    !isUsageLoading &&
    usageDetails !== undefined &&
    !isBlocked &&
    priorUsages.length > 0;

  const handleDelete = async () => {
    try {
      await deleteColor(colorId).unwrap();
      dispatch(
        componentAndLayoutApi.util.invalidateTags([
          { type: 'Folders', id: 'LIST' },
        ]),
      );
      reset();
      dispatch(setDialogWithDataClosed('deleteColorConfirm'));
    } catch (err) {
      console.error('Failed to delete color:', err);
    }
  };

  const errorMessage =
    isError && error
      ? 'data' in error
        ? (error.data as { message?: string })?.message
        : String(error)
      : null;

  const blockingCount = currentUsages.length + configUsages.length;

  const handleOpenChange = (open: boolean) => {
    if (!open) {
      reset();
      dispatch(setDialogWithDataClosed('deleteColorConfirm'));
    }
  };

  const description = (
    <Box data-testid="canvas-delete-color-dialog-body">
      {isUsageLoading && (
        <Flex justify="center">
          <Spinner />
        </Flex>
      )}

      {!isUsageLoading && isUsageError && (
        <Text size="2" role="alert">
          Unable to get usage data for <b>{colorName}</b>. Try again.
        </Text>
      )}

      {!isUsageLoading && isBlocked && (
        <Text size="2">
          {blockingCount > 0 ? (
            <>
              <b>{colorName}</b> is in use on{' '}
              {blockingCount === 1
                ? '1 page or template'
                : `${blockingCount} pages or templates`}
              . Remove all uses before deleting.
            </>
          ) : (
            <>
              {/* Blocked by an unpublished change or a published revision
                that is no longer the latest, so there is nothing to
                list here. */}
              <b>{colorName}</b> is still in use. Remove all uses before
              deleting.
            </>
          )}
        </Text>
      )}

      {!isUsageLoading && !isBlocked && !isUsageError && (
        <Text size="2">
          You are about to permanently delete the <b>{colorName}</b> color.
          {hasPriorOnlyWarning && (
            <>
              <br />
              <br />
              This will break{' '}
              <b>
                {priorUsages.length} past version
                {priorUsages.length > 1 ? 's' : ''}
              </b>
              . Reverting to past versions that rely on this color will appear
              broken.
            </>
          )}
        </Text>
      )}
    </Box>
  );
  return (
    <Dialog
      width="320px"
      open={isDeleteDialogOpen}
      onOpenChange={handleOpenChange}
      title={
        <>
          <TrashIcon className={styles.titleIcon} />
          Delete color
        </>
      }
      error={
        isError
          ? {
              title: 'Failed to delete color',
              message: errorMessage,
              resetButtonText: 'Try again',
              onReset: handleDelete,
            }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Delete',
        onConfirm: handleDelete,
        isConfirmDisabled: isDeleteDisabled,
        isConfirmLoading: isDeleting,
        isDanger: true,
      }}
      description={description}
    />
  );
};

export default DeleteColorDialog;
