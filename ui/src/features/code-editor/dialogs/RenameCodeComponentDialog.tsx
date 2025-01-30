import { useState, useEffect } from 'react';
import { Flex, TextField } from '@radix-ui/themes';
import { useUpdateCodeComponentMutation } from '@/services/codeComponents';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  closeAllDialogs,
  selectDialogStates,
  selectSelectedCodeComponent,
} from '@/features/ui/codeComponentDialogSlice';
import Dialog, { DialogFieldLabel } from '@/components/Dialog';

const RenameCodeComponentDialog = () => {
  const selectedComponent = useAppSelector(selectSelectedCodeComponent);
  const [componentName, setComponentName] = useState('');
  const [updateCodeComponent, { isLoading, isSuccess, isError, error, reset }] =
    useUpdateCodeComponentMutation();
  const dispatch = useAppDispatch();
  const { isRenameDialogOpen } = useAppSelector(selectDialogStates);

  useEffect(() => {
    if (selectedComponent) {
      setComponentName(selectedComponent.name);
    }
  }, [selectedComponent]);

  const handleSave = async () => {
    if (!selectedComponent) return;

    await updateCodeComponent({
      id: selectedComponent.machineName,
      changes: {
        name: componentName,
        machineName: selectedComponent.machineName,
        status: selectedComponent.status,
      },
    });
  };

  const handleOpenChange = (open: boolean) => {
    if (!open) {
      setComponentName('');
      reset();
      dispatch(closeAllDialogs());
    }
  };

  useEffect(() => {
    if (isSuccess) {
      setComponentName('');
      dispatch(closeAllDialogs());
    }
  }, [isSuccess, dispatch]);

  useEffect(() => {
    if (isError) {
      console.error('Failed to rename code component:', error);
    }
  }, [isError, error]);

  return (
    <Dialog
      open={isRenameDialogOpen}
      onOpenChange={handleOpenChange}
      title="Rename code component"
      error={
        isError
          ? {
              title: 'Failed to rename code component',
              message: `An error ${
                'status' in error ? '(HTTP ' + error.status + ')' : ''
              } occurred while renaming the code component. Please check the browser console for more details.`,
              resetButtonText: 'Try again',
              onReset: handleSave,
            }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Rename',
        onConfirm: handleSave,
        isConfirmDisabled:
          !componentName.trim() || componentName === selectedComponent?.name,
        isConfirmLoading: isLoading,
      }}
    >
      <Flex direction="column" gap="2">
        <DialogFieldLabel>Component name</DialogFieldLabel>
        <TextField.Root
          value={componentName}
          onChange={(e) => setComponentName(e.target.value)}
          placeholder="Enter a new name"
          size="1"
        />
      </Flex>
    </Dialog>
  );
};

export default RenameCodeComponentDialog;
