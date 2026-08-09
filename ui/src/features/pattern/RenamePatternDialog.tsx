import { useEffect, useState } from 'react';
import { Flex, TextField } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import {
  selectDialogOpen,
  setDialogWithDataClosed,
} from '@/features/ui/dialogSlice';
import { useUpdatePatternMutation } from '@/services/patterns';

import type { Pattern } from '@/types/Pattern';

const RenamePatternDialog = () => {
  const dispatch = useAppDispatch();
  const { renamePatternConfirm } = useAppSelector(selectDialogOpen);
  const { open, data } = renamePatternConfirm;
  const { name, id } = data as Pattern;
  const [patternName, setPatternName] = useState('');
  const [updatePattern, { isLoading, isSuccess, isError, error, reset }] =
    useUpdatePatternMutation();
  const isEmptyOrUnchanged = !patternName.trim() || patternName === name;

  // Prefill the input with the current name whenever the dialog opens for a
  // pattern.
  useEffect(() => {
    if (open) {
      setPatternName(name ?? '');
    }
  }, [open, name]);

  const handleRename = async () => {
    if (!id || isEmptyOrUnchanged) {
      return;
    }
    await updatePattern({ id, name: patternName });
  };

  const handleOpenChange = (nextOpen: boolean) => {
    if (!nextOpen) {
      reset();
      dispatch(setDialogWithDataClosed('renamePatternConfirm'));
    }
  };

  useEffect(() => {
    if (isSuccess) {
      dispatch(setDialogWithDataClosed('renamePatternConfirm'));
    }
    if (isError) {
      console.error('Failed to rename pattern:', error);
    }
  }, [isSuccess, isError, dispatch, error]);

  return (
    <Dialog
      open={open}
      onOpenChange={handleOpenChange}
      title={Drupal.t('Rename pattern')}
      error={
        isError
          ? {
              title: Drupal.t('Failed to rename pattern'),
              message:
                'status' in error
                  ? Drupal.t(
                      'An error (HTTP !status) occurred while renaming the pattern. Please check the browser console for more details.',
                      { '!status': error.status },
                    )
                  : Drupal.t(
                      'An error occurred while renaming the pattern. Please check the browser console for more details.',
                    ),
              resetButtonText: Drupal.t('Try again'),
              onReset: handleRename,
            }
          : undefined
      }
      footer={{
        cancelText: Drupal.t('Cancel'),
        confirmText: Drupal.t('Rename'),
        onConfirm: handleRename,
        isConfirmDisabled: isEmptyOrUnchanged,
        isConfirmLoading: isLoading,
      }}
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          handleRename();
        }}
      >
        <Flex direction="column" gap="2">
          <DialogFieldLabel htmlFor="patternRenameInput">
            {Drupal.t('Pattern name')}
          </DialogFieldLabel>
          <TextField.Root
            autoComplete="off"
            id="patternRenameInput"
            value={patternName}
            onChange={(e) => setPatternName(e.target.value)}
            placeholder={Drupal.t('Enter a new name')}
            size="1"
          />
        </Flex>
      </form>
    </Dialog>
  );
};

export default RenamePatternDialog;
