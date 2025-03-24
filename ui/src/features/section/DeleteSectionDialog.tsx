import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectDialogOpen,
  setDialogWithDataClosed,
} from '@/features/ui/dialogSlice';
import { useDeleteSectionMutation } from '@/services/sections';
import Dialog from '@/components/Dialog';
import { useEffect } from 'react';
import type { Section } from '@/types/Section';

const DeleteSectionDialog = () => {
  const dispatch = useAppDispatch();
  const { deleteSectionConfirm } = useAppSelector(selectDialogOpen);
  const { open, data } = deleteSectionConfirm;
  const [deleteSection, { isLoading, isSuccess, isError, error, reset }] =
    useDeleteSectionMutation();
  const { name, id } = data as Section;

  const handleDelete = async () => {
    await deleteSection(id);
  };

  const handleOpenChange = (open: boolean) => {
    if (!open) {
      reset();
      dispatch(setDialogWithDataClosed('deleteSectionConfirm'));
    }
  };

  useEffect(() => {
    if (isSuccess) {
      dispatch(setDialogWithDataClosed('deleteSectionConfirm'));
    }
    if (isError) {
      console.error('Failed to delete section:', error);
    }
  }, [isSuccess, isError, dispatch, error]);

  return (
    <Dialog
      open={open}
      onOpenChange={handleOpenChange}
      title="Delete section"
      description={`Are you sure you want to delete "${name}"? This action cannot be undone.`}
      error={
        isError
          ? {
              title: 'Failed to delete section',
              message: `An error ${
                'status' in error ? '(HTTP ' + error.status + ')' : ''
              } occurred while deleting the section. Please check the browser console for more details.`,
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
      }}
    />
  );
};

export default DeleteSectionDialog;
