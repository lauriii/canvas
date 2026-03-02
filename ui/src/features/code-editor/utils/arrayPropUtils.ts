import { arrayMove } from '@dnd-kit/sortable';

import { updateProp } from '@/features/code-editor/codeEditorSlice';

import type { DragEndEvent } from '@dnd-kit/core';
import type { AppDispatch } from '@/app/store';
import type { CodeComponentProp } from '@/types/CodeComponent';

/**
 * Utility functions for handling array-based prop values in component forms.
 * These functions are used across multiple form prop type components to manage
 * multiple values, drag-and-drop reordering, and array manipulations.
 */

/**
 * Creates a drag end handler for reordering array items
 * @param displayArray - The current array of values to reorder
 * @param dispatch - Redux dispatch function
 * @param id - The prop ID
 * @param additionalUpdates - Optional additional updates to include in the dispatch
 * @returns A function that handles the drag end event
 */
export function createArrayDragEndHandler<T extends string | number>(
  displayArray: T[],
  dispatch: AppDispatch,
  id: CodeComponentProp['id'],
  additionalUpdates?: Partial<CodeComponentProp>,
) {
  return (event: DragEndEvent) => {
    const { active, over } = event;

    if (over && active.id !== over.id) {
      const oldIndex = Number(active.id);
      const newIndex = Number(over.id);
      const newExample = arrayMove([...displayArray], oldIndex, newIndex) as
        | string[]
        | number[];
      dispatch(
        updateProp({
          id,
          updates: { example: newExample, ...additionalUpdates },
        }),
      );
    }
  };
}

/**
 * Adds a new item to the array
 * @param displayArray - The current array of values
 * @param dispatch - Redux dispatch function
 * @param id - The prop ID
 * @param defaultValue - The default value to add
 * @param additionalUpdates - Optional additional updates to include in the dispatch
 */
export function handleArrayAdd<T extends string | number>(
  displayArray: T[],
  dispatch: AppDispatch,
  id: CodeComponentProp['id'],
  defaultValue: T,
  additionalUpdates?: Partial<CodeComponentProp>,
) {
  const newExample = [...displayArray, defaultValue] as string[] | number[];
  dispatch(
    updateProp({
      id,
      updates: { example: newExample, ...additionalUpdates },
    }),
  );
}

/**
 * Removes an item from the array at the specified index
 * @param displayArray - The current array of values
 * @param dispatch - Redux dispatch function
 * @param id - The prop ID
 * @param index - The index of the item to remove
 * @param additionalUpdates - Optional additional updates to include in the dispatch
 */
export function handleArrayRemove<T extends string | number>(
  displayArray: T[],
  dispatch: AppDispatch,
  id: CodeComponentProp['id'],
  index: number,
  additionalUpdates?: Partial<CodeComponentProp>,
) {
  const newExample = displayArray.filter((_, i) => i !== index) as
    | string[]
    | number[];
  dispatch(
    updateProp({
      id,
      updates: { example: newExample, ...additionalUpdates },
    }),
  );
}

/**
 * Updates a single item in the array at the specified index
 * @param displayArray - The current array of values
 * @param dispatch - Redux dispatch function
 * @param id - The prop ID
 * @param index - The index of the item to update
 * @param value - The new value
 * @param additionalUpdates - Optional additional updates to include in the dispatch
 */
export function handleArrayValueChange<T extends string | number>(
  displayArray: T[],
  dispatch: AppDispatch,
  id: CodeComponentProp['id'],
  index: number,
  value: T,
  additionalUpdates?: Partial<CodeComponentProp>,
) {
  const newExample = [...displayArray] as (string | number)[];
  newExample[index] = value;
  const typedExample = newExample as string[] | number[];
  dispatch(
    updateProp({
      id,
      updates: { example: typedExample, ...additionalUpdates },
    }),
  );
}
