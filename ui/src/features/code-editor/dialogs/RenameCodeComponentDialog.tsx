import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Flex, TextField } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import {
  getComponentId,
  removeJsPrefix,
} from '@/components/list/CodeComponentItem';
import {
  selectCodeComponentProperty,
  setCodeComponentProperty,
  setForceRefresh,
} from '@/features/code-editor/codeEditorSlice';
import {
  closeAllDialogs,
  selectDialogStates,
  selectSelectedCodeComponent,
} from '@/features/ui/codeComponentDialogSlice';
import { useUpdateCodeComponentMutation } from '@/services/componentAndLayout';

const RenameCodeComponentDialog = () => {
  const selectedComponent = useAppSelector(selectSelectedCodeComponent);
  const codeEditorId = useAppSelector(
    selectCodeComponentProperty('machineName'),
  );
  const [componentName, setComponentName] = useState('');
  const [updateCodeComponent, { isLoading, isSuccess, isError, error, reset }] =
    useUpdateCodeComponentMutation();
  const dispatch = useAppDispatch();
  const { isRenameDialogOpen } = useAppSelector(selectDialogStates);
  const { codeComponentId: codeComponentBeingEditedId } = useParams();
  const isEmptyOrUnchanged =
    !componentName.trim() || componentName === selectedComponent?.name;

  useEffect(() => {
    if (selectedComponent) {
      setComponentName(selectedComponent.name);
    }
  }, [selectedComponent]);

  const handleSave = async () => {
    if (!selectedComponent) return;
    // The selected component can be either a CodeComponentSerialized or a JSComponent.
    // If it's type JSComponent (Component entity), we need to get the machine name from the id property.
    // If it's type CodeComponentSerialized, we get it from machineName property.
    // @see selectSelectedCodeComponent in `codeComponentDialogSlice.ts`
    const componentId = getComponentId(selectedComponent);
    const machineName = removeJsPrefix(componentId);

    await updateCodeComponent({
      id: machineName,
      changes: {
        name: componentName,
      },
    });
    if (codeEditorId === machineName) {
      if (codeEditorId === codeComponentBeingEditedId) {
        // The code editor typically won't check auto-save updates if the
        // component being edited is the same as the one being updated. Force a
        // refresh to avoid auto-save mismatch errors.
        dispatch(setForceRefresh(true));
      }
      dispatch(setCodeComponentProperty(['name', componentName]));
    }
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
      console.error('Failed to rename component:', error);
    }
  }, [isError, error]);

  return (
    <Dialog
      open={isRenameDialogOpen}
      onOpenChange={handleOpenChange}
      title="Rename component"
      error={
        isError
          ? {
              title: 'Failed to rename component',
              message: `An error ${
                'status' in error ? '(HTTP ' + error.status + ')' : ''
              } occurred while renaming the component. Please check the browser console for more details.`,
              resetButtonText: 'Try again',
              onReset: handleSave,
            }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Rename',
        onConfirm: handleSave,
        isConfirmDisabled: isEmptyOrUnchanged,
        isConfirmLoading: isLoading,
      }}
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (isEmptyOrUnchanged) {
            return;
          }
          handleSave();
        }}
      >
        <Flex direction="column" gap="2">
          <DialogFieldLabel htmlFor={'componentName'}>
            Component name
          </DialogFieldLabel>
          <TextField.Root
            autoComplete="off"
            id={'componentName'}
            value={componentName}
            onChange={(e) => setComponentName(e.target.value)}
            placeholder="Enter a new name"
            size="1"
          />
        </Flex>
      </form>
    </Dialog>
  );
};

export default RenameCodeComponentDialog;
