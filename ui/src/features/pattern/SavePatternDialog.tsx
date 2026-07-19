import { useCallback, useEffect, useMemo, useState } from 'react';
import { Flex, TextField } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import {
  findComponentByUuid,
  recurseNodes,
  sortUuidsByDocumentOrder,
} from '@/features/layout/layoutUtils';
import {
  selectDialogOpen,
  setDialogClosed,
  setDialogOpen,
} from '@/features/ui/dialogSlice';
import { selectSelection } from '@/features/ui/uiSlice';
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
      return 'The server returned an unexpected response format.';
    }
    if (error.status === 404) {
      return 'Resource not found.';
    }
    // Handle other HTTP status errors generically
    const errorData = error.data as ErrorData;
    return `Error ${error.status}: ${errorData?.message || 'No additional information'}`;
  } else {
    // Handle SerializedError
    return error.message || 'Unknown error occurred';
  }
}

const SavePatternDialog: React.FC = () => {
  const { saveAsPattern } = useAppSelector(selectDialogOpen);
  const dispatch = useAppDispatch();
  const selection = useAppSelector(selectSelection);
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  // The pattern contains every selected component subtree, in document order.
  // A single selection is simply a list of one.
  const selectedUuids = useMemo(
    () => sortUuidsByDocumentOrder(layout, selection.items),
    [layout, selection.items],
  );
  const selectedComponent = selectedUuids[0];
  const selectedNode = findComponentByUuid(layout, selectedComponent || '');
  const selectedComponentName = useGetComponentName(selectedNode);
  const isMultiSelect = selectedUuids.length > 1;
  const [patternName, setPatternName] = useState('My pattern');
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
    if (isMultiSelect) {
      setPatternName(`${selectedUuids.length} components pattern`);
    } else if (selectedComponent) {
      setPatternName(`${selectedComponentName} pattern`);
    }
  }, [
    model,
    selectedComponent,
    selectedComponentName,
    isMultiSelect,
    selectedUuids.length,
  ]);

  const handleSaveClick = useCallback(() => {
    if (!selectedUuids.length || !layout) {
      return;
    }

    const nodesToSave = [];
    const modelsToSave = {} as typeof model;
    for (const uuid of selectedUuids) {
      const thisNode = findComponentByUuid(layout, uuid);
      if (!thisNode) {
        return;
      }
      nodesToSave.push(thisNode);
      modelsToSave[uuid] = model[uuid];
      recurseNodes(thisNode, (node) => {
        if (model[node.uuid]) {
          modelsToSave[node.uuid] = model[node.uuid];
        }
      });
    }

    savePattern({
      layout: nodesToSave,
      model: modelsToSave,
      name: patternName,
    });
  }, [layout, model, savePattern, selectedUuids, patternName]);

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

  if (!selectedUuids.length) {
    return null;
  }

  const patternSubject = isMultiSelect
    ? `these ${selectedUuids.length} components`
    : `this configuration of "${selectedComponentName}"`;

  return (
    <Dialog
      open={saveAsPattern}
      onOpenChange={handleOpenChange}
      title="Add new pattern"
      description={`Saving ${patternSubject} as a pattern allows it to be used again later and customized there without affecting other copies.`}
      error={
        isError
          ? {
              title: 'Failed to save pattern',
              message: getErrorMessage(error),
              resetButtonText: 'Try again',
              onReset: handleSaveClick,
            }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Add to library',
        onConfirm: handleSaveClick,
        isConfirmDisabled: !patternName.trim(),
        isConfirmLoading: isSaving,
      }}
    >
      <Flex direction="column" gap="2">
        <label>
          <DialogFieldLabel htmlFor={'patternName'}>
            Pattern name
          </DialogFieldLabel>
          <TextField.Root
            autoComplete="off"
            value={patternName}
            onChange={handleInputChange}
            placeholder="Enter a name"
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
