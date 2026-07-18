import { useCallback, useEffect, useMemo, useState } from 'react';
import { Flex, TextField } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import {
  isEvaluatedComponentModel,
  selectLayout,
  selectModel,
} from '@/features/layout/layoutModelSlice';
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
import type { ComponentModel } from '@/features/layout/layoutModelSlice';

interface ErrorData {
  message?: string;
}

// A pattern is inserted by value and has no host entity, so it cannot resolve
// props linked to host-entity fields. These are `EntityFieldPropSource`s, whose
// sourceType is `entity-field` (or the backwards-compatible legacy alias
// `dynamic`, still carried by content templates that have not been re-saved).
// @see \Drupal\canvas\PropSource\PropSource
// @see \Drupal\canvas\PropSource\EntityFieldPropSource
const LINKED_PROP_SOURCE_TYPES = ['entity-field', 'dynamic'];

const LINKED_PROP_MESSAGE =
  'This selection has fields linked to entity data. A pattern can be placed ' +
  'anywhere, so it cannot reference a specific entity’s fields. Unlink ' +
  'those fields and try again.';

// Returns true when a component model links at least one prop to an entity
// field.
function modelHasLinkedProp(model: ComponentModel | undefined): boolean {
  if (!model || !isEvaluatedComponentModel(model)) {
    return false;
  }
  return Object.values(model.source).some((source) =>
    LINKED_PROP_SOURCE_TYPES.includes(source.sourceType),
  );
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
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const selectedNode = findComponentByUuid(layout, selectedComponent || '');
  const selectedComponentName = useGetComponentName(selectedNode);
  const [patternName, setPatternName] = useState('My pattern');
  const [
    savePattern,
    { isLoading: isSaving, isSuccess, isError, error, reset },
  ] = useSavePatternMutation();

  // A pattern cannot contain props linked to host-entity fields. Detect any such
  // linked prop in the selected subtree (the root plus its descendants, matching
  // the payload built in handleSaveClick) so the user gets a clear explanation
  // instead of a request that fails server-side.
  const hasLinkedProps = useMemo(() => {
    if (!selectedComponent || !layout) {
      return false;
    }
    const rootNode = findComponentByUuid(layout, selectedComponent);
    if (!rootNode) {
      return false;
    }
    if (modelHasLinkedProp(model[selectedComponent])) {
      return true;
    }
    let found = false;
    recurseNodes(rootNode, (node) => {
      if (modelHasLinkedProp(model[node.uuid])) {
        found = true;
      }
    });
    return found;
  }, [layout, model, selectedComponent]);

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
      setPatternName(`${selectedComponentName} pattern`);
    }
  }, [model, selectedComponent, selectedComponentName]);

  const handleSaveClick = useCallback(() => {
    if (!selectedComponent || !layout || hasLinkedProps) {
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
  }, [
    layout,
    model,
    savePattern,
    selectedComponent,
    patternName,
    hasLinkedProps,
  ]);

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
      title="Add new pattern"
      description={`Saving this configuration of "${selectedComponentName}" as a pattern allows it to be used again later and customized there without affecting other copies.`}
      error={
        hasLinkedProps
          ? {
              title: 'This can’t be saved as a pattern',
              message: LINKED_PROP_MESSAGE,
            }
          : isError
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
        isConfirmDisabled: !patternName.trim() || hasLinkedProps,
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
