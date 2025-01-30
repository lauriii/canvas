import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Flex, TextField } from '@radix-ui/themes';
import { useCreateCodeComponentMutation } from '@/services/codeComponents';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  closeAllDialogs,
  selectDialogStates,
} from '@/features/ui/codeComponentDialogSlice';
import Dialog, { DialogFieldLabel } from '@/components/Dialog';

const AddCodeComponentDialog = () => {
  const [componentName, setComponentName] = useState('');
  const [
    createCodeComponent,
    { isLoading, isSuccess, isError, error, reset, data },
  ] = useCreateCodeComponentMutation();
  const navigate = useNavigate();
  const dispatch = useAppDispatch();
  const { isAddDialogOpen } = useAppSelector(selectDialogStates);

  const handleSave = async () => {
    await createCodeComponent({
      name: componentName,
      machineName: componentName.toLowerCase().replace(/\s+/g, '_'),
      status: true,
      source_code_js: '',
      source_code_css: '',
      compiled_js: '',
      compiled_css: '',
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
    if (isSuccess && data?.machineName) {
      setComponentName('');
      dispatch(closeAllDialogs());
      navigate(`/code-editor/code/${data.machineName}`);
    }
  }, [isSuccess, data?.machineName, dispatch, navigate]);

  useEffect(() => {
    if (isError) {
      console.error('Failed to add code component:', error);
    }
  }, [isError, error]);

  return (
    <Dialog
      open={isAddDialogOpen}
      onOpenChange={handleOpenChange}
      title="Add new code component"
      error={
        isError
          ? {
              title: 'Failed to add code component',
              message: `An error ${
                'status' in error ? '(HTTP ' + error.status + ')' : ''
              } occurred while creating the code component. Please check the browser console for more details.`,
              resetButtonText: 'Try again',
              onReset: handleSave,
            }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Add',
        onConfirm: handleSave,
        isConfirmDisabled: !componentName.trim(),
        isConfirmLoading: isLoading,
      }}
    >
      <Flex direction="column" gap="2">
        <DialogFieldLabel>Component name</DialogFieldLabel>
        <TextField.Root
          value={componentName}
          onChange={(e) => setComponentName(e.target.value)}
          placeholder="Enter a name"
          size="1"
        />
      </Flex>
    </Dialog>
  );
};

export default AddCodeComponentDialog;
