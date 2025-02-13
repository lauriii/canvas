import type React from 'react';
import { useCallback, useEffect, useState } from 'react';
import { Flex, TextField } from '@radix-ui/themes';
import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import {
  selectDialogOpen,
  setDialogOpen,
  setDialogClosed,
} from '@/features/ui/dialogSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import {
  findComponentByUuid,
  recurseNodes,
} from '@/features/layout/layoutUtils';
import { useSaveSectionMutation } from '@/services/sections';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query/react';
import type { SerializedError } from '@reduxjs/toolkit';
import useGetComponentName from '@/hooks/useGetComponentName';
import useXbParams from '@/hooks/useXbParams';

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

const SaveSectionDialog: React.FC = () => {
  const { saveAsSection } = useAppSelector(selectDialogOpen);
  const dispatch = useAppDispatch();
  const { componentId: selectedComponent } = useXbParams();
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const selectedNode = findComponentByUuid(layout, selectedComponent || '');
  const selectedComponentName = useGetComponentName(selectedNode);
  const [sectionName, setSectionName] = useState('My section');
  const [
    saveSection,
    { isLoading: isSaving, isSuccess, isError, error, reset },
  ] = useSaveSectionMutation();

  const handleOpenChange = useCallback(
    (open: boolean) => {
      open
        ? dispatch(setDialogOpen('saveAsSection'))
        : dispatch(setDialogClosed('saveAsSection'));
      if (!open) {
        reset();
      }
    },
    [dispatch, reset],
  );

  useEffect(() => {
    if (selectedComponent) {
      setSectionName(`${selectedComponentName} section`);
    }
  }, [model, selectedComponent, selectedComponentName]);

  const handleSaveClick = useCallback(() => {
    if (!selectedComponent || !layout) {
      return;
    }

    let modelsToSave = {
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

    saveSection({
      layout: [thisNode],
      model: modelsToSave,
      name: sectionName,
    });
  }, [layout, model, saveSection, selectedComponent, sectionName]);

  const handleInputChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    setSectionName(event.target.value);
  };

  useEffect(() => {
    if (isSuccess) {
      dispatch(setDialogClosed('saveAsSection'));
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
      open={saveAsSection}
      onOpenChange={handleOpenChange}
      title="Add new section"
      description={`Save "${selectedComponentName}" as a section. Unlike components, sections are independent and can be customized without affecting other instances.`}
      error={
        isError
          ? {
              title: 'Failed to save section',
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
        isConfirmDisabled: !sectionName.trim(),
        isConfirmLoading: isSaving,
      }}
    >
      <Flex direction="column" gap="2">
        <label>
          <DialogFieldLabel>Section name</DialogFieldLabel>
          <TextField.Root
            value={sectionName}
            onChange={handleInputChange}
            placeholder="Enter a name"
            id="sectionName"
            name="sectionName"
            size="1"
          />
        </label>
      </Flex>
    </Dialog>
  );
};

export default SaveSectionDialog;
