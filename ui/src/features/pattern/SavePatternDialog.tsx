import { useCallback, useEffect, useState } from 'react';
import { Flex, TextField } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import {
  findComponentByUuid,
  recurseNodes,
} from '@/features/layout/layoutUtils';
import {
  selectDialogOpen,
  setDialogClosed,
  setDialogOpen,
} from '@/features/ui/dialogSlice';
import { selectSelectedComponentUuid } from '@/features/ui/uiSlice';
import useGetComponentName from '@/hooks/useGetComponentName';
import { useSavePatternMutation } from '@/services/patterns';

import type React from 'react';
import type { SerializedError } from '@reduxjs/toolkit';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query/react';

interface ErrorData {
  message?: string;
}

function getErrorMessage(error: FetchBaseQueryError | SerializedError): string {
  if ('status' in error) {
    // TODO: I think any calls to /api/ should respond in JSON, not an HTML document?
    if (error.status === 'PARSING_ERROR') {
      return Drupal.t('The server returned an unexpected response format.');
    }
    if (error.status === 404) {
      return Drupal.t('Resource not found.');
    }
    // Handle other HTTP status errors generically
    const errorData = error.data as ErrorData;
    // Kept out of the argument list because Drupal's scanner reads the source
    // as text, and a Drupal.t() call nested inside another call's arguments is
    // not reliably extractable.
    const noDetail = Drupal.t('No additional information');
    return Drupal.t('Error !status: !message', {
      '!status': error.status,
      '!message': errorData?.message || noDetail,
    });
  } else {
    // Handle SerializedError
    return error.message || Drupal.t('Unknown error occurred');
  }
}

const SavePatternDialog: React.FC = () => {
  const { saveAsPattern } = useAppSelector(selectDialogOpen);
  const dispatch = useAppDispatch();
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const selectedNode = findComponentByUuid(layout, selectedComponent || '');
  const selectedComponentName = useGetComponentName(selectedNode);
  const [patternName, setPatternName] = useState(Drupal.t('My pattern'));
  const [
    savePattern,
    { isLoading: isSaving, isSuccess, isError, error, reset },
  ] = useSavePatternMutation();

  const handleOpenChange = useCallback(
    (open: boolean) => {
      open
        ? dispatch(setDialogOpen('saveAsPattern'))
        : dispatch(setDialogClosed('saveAsPattern'));
      if (!open) {
        reset();
      }
    },
    [dispatch, reset],
  );

  useEffect(() => {
    if (selectedComponent) {
      setPatternName(
        Drupal.t('!name pattern', { '!name': selectedComponentName }),
      );
    }
  }, [model, selectedComponent, selectedComponentName]);

  const handleSaveClick = useCallback(() => {
    if (!selectedComponent || !layout) {
      return;
    }

    const modelsToSave = {
      [selectedComponent]: model[selectedComponent],
    };
    const thisNode = findComponentByUuid(layout, selectedComponent);
    if (!thisNode) {
      return;
    }

    recurseNodes(thisNode, (node) => {
      if (model[node.uuid]) {
        modelsToSave[node.uuid] = model[node.uuid];
      }
    });

    savePattern({
      layout: [thisNode],
      model: modelsToSave,
      name: patternName,
    });
  }, [layout, model, savePattern, selectedComponent, patternName]);

  const handleInputChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    setPatternName(event.target.value);
  };

  useEffect(() => {
    if (isSuccess) {
      dispatch(setDialogClosed('saveAsPattern'));
    }
    if (isError) {
      console.error('Save failed', error);
    }
  }, [isSuccess, isError, dispatch, error]);

  if (!selectedComponent) {
    return null;
  }

  return (
    <Dialog
      open={saveAsPattern}
      onOpenChange={handleOpenChange}
      title={Drupal.t('Add new pattern')}
      description={Drupal.t(
        'Saving this configuration of "!name" as a pattern allows it to be used again later and customized there without affecting other copies.',
        { '!name': selectedComponentName },
      )}
      error={
        isError
          ? {
              title: Drupal.t('Failed to save pattern'),
              message: getErrorMessage(error),
              resetButtonText: Drupal.t('Try again'),
              onReset: handleSaveClick,
            }
          : undefined
      }
      footer={{
        cancelText: Drupal.t('Cancel'),
        confirmText: Drupal.t('Add to library'),
        onConfirm: handleSaveClick,
        isConfirmDisabled: !patternName.trim(),
        isConfirmLoading: isSaving,
      }}
    >
      <Flex direction="column" gap="2">
        <label>
          <DialogFieldLabel htmlFor={'patternName'}>
            {Drupal.t('Pattern name')}
          </DialogFieldLabel>
          <TextField.Root
            autoComplete="off"
            value={patternName}
            onChange={handleInputChange}
            placeholder={Drupal.t('Enter a name')}
            id="patternName"
            name="patternName"
            size="1"
          />
        </label>
      </Flex>
    </Dialog>
  );
};

export default SavePatternDialog;
