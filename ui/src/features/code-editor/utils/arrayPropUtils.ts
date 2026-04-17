import { arrayMove } from '@dnd-kit/sortable';

import { updateProp } from '@/features/code-editor/codeEditorSlice';
import { VALUE_MODE_LIMITED } from '@/types/CodeComponent';

import type { DragEndEvent } from '@dnd-kit/core';
import type { AppDispatch } from '@/app/store';
import type { CodeComponentProp, ValueMode } from '@/types/CodeComponent';

/**
 * Utility functions for handling array-based prop values in component forms.
 * These functions are used across multiple form prop type components to manage
 * multiple values, drag-and-drop reordering, and array manipulations.
 */

/**
 * Helper function to dispatch prop updates, reducing code repetition across
 * form components.
 *
 * Prefer this over calling `dispatch(updateProp(...))` directly to keep
 * callers concise and consistently typed.
 *
 * @param dispatch - The Redux dispatch function from useAppDispatch hook.
 * @param id - The prop ID to update.
 * @param updates - The updates to merge into the prop (typed as Partial<CodeComponentProp>).
 */
export function dispatchUpdateProp(
  dispatch: AppDispatch,
  id: string,
  updates: Partial<CodeComponentProp>,
): void {
  dispatch(updateProp({ id, updates }));
}

/**
 * Returns true if the given value contains at least one non-empty array item.
 *
 * Pass `allowMultiple ? example : []` from components that conditionally
 * operate in multi-value mode so the caller doesn't have to repeat the
 * guard each time.
 *
 * @param example - The value to inspect (may or may not be an array).
 */
export function hasNonEmptyArrayValue(example: unknown): boolean {
  const arr = Array.isArray(example) ? example : [];
  return (arr as unknown[]).some(
    (v) => v !== '' && v !== undefined && v !== null,
  );
}

/**
 * Creates a display array for rendering multivalue props.
 *
 * @param example - The example value(s) from the prop.
 * @param valueMode - The value mode ('limited' or 'unlimited').
 * @param limitedCount - The count limit for limited mode.
 * @param allowMultiple - Whether multiple values are allowed.
 * @returns The display array.
 */
export function createDisplayArray<T extends string | number>(
  example: T | T[] | (string | number)[],
  valueMode?: ValueMode,
  limitedCount?: number,
  allowMultiple?: boolean,
): (string | number)[] {
  const exampleArray = Array.isArray(example) ? example : [];

  // In limited mode, ensure we have exactly limitedCount items.
  if (
    (allowMultiple === undefined || allowMultiple) &&
    valueMode === VALUE_MODE_LIMITED &&
    limitedCount
  ) {
    return Array.from(
      { length: limitedCount },
      (_, i) => exampleArray[i] ?? '',
    );
  }

  // In unlimited mode, ensure we always have at least one item to display.
  return exampleArray.length === 0 ? [''] : exampleArray;
}

/**
 * Creates a drag end handler for reordering array items
 * @param displayArray - The current array of values to reorder
 * @param dispatch - Redux dispatch function
 * @param id - The prop ID
 * @param additionalUpdates - Optional additional updates to include in the dispatch
 * @param onReorder - Optional callback invoked with (oldIndex, newIndex) after reorder
 * @returns A function that handles the drag end event
 */
export function createArrayDragEndHandler<T extends string | number>(
  displayArray: T[],
  dispatch: AppDispatch,
  id: CodeComponentProp['id'],
  additionalUpdates?: Partial<CodeComponentProp>,
  onReorder?: (oldIndex: number, newIndex: number) => void,
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
      onReorder?.(oldIndex, newIndex);
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
