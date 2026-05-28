import { useEffect, useRef } from 'react';

import { useAppSelector } from '@/app/hooks';
import {
  selectLatestUndoRedoActionId,
  selectUndoRedoMetadata,
} from '@/features/ui/uiSlice';

import type { UseFormReturn } from 'react-hook-form';

/**
 * Hook that syncs React Hook Form state with Redux state during undo/redo actions
 *
 * @param fieldName - The name of the field to sync
 * @param getCurrentValue - Function to get the current value from Redux
 * @param rhfContext - React Hook Form context
 * @param targetSlice - The Redux slice to watch for undo/redo ('layoutModel' or 'pageData')
 */
export const useUndoRedoSync = (
  fieldName: string,
  getCurrentValue: () => any,
  rhfContext: UseFormReturn | null,
  targetSlice: 'layoutModel' | 'pageData',
) => {
  const latestUndoRedoActionId = useAppSelector(selectLatestUndoRedoActionId);
  const undoRedoMetadata = useAppSelector(selectUndoRedoMetadata);
  const previousUndoRedoIdRef = useRef<string>('');

  useEffect(() => {
    // Skip on initial mount
    if (!latestUndoRedoActionId) {
      return;
    }

    // Skip if this undo/redo is not for the target slice
    if (undoRedoMetadata.targetSlice !== targetSlice) {
      return;
    }

    // If undo/redo occurred, sync react-hook-form with current Redux state
    if (
      latestUndoRedoActionId !== previousUndoRedoIdRef.current &&
      rhfContext
    ) {
      const currentValue = getCurrentValue();
      rhfContext.setValue(fieldName, currentValue, {
        shouldValidate: false,
        shouldDirty: false,
        shouldTouch: false,
      });
      previousUndoRedoIdRef.current = latestUndoRedoActionId;
    }
  }, [
    latestUndoRedoActionId,
    undoRedoMetadata,
    fieldName,
    getCurrentValue,
    rhfContext,
    targetSlice,
  ]);
};
