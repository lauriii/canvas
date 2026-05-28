import { useEffect, useRef } from 'react';
import { debounce } from 'lodash';

import { DEBOUNCE_TIMEOUT } from '@/components/form/react-hook-form/fields/componentFormData';

/**
 * Returns a stable debounced version of formStateToStore and cancels any
 * pending call on unmount.
 *
 * @param formStateToStore - The function to debounce for form state updates
 * @returns The debounced version of the formStateToStore function
 */
export const useDebouncedFormState = (
  formStateToStore: (...args: any[]) => any,
) => {
  // Always call the latest version of the callback.
  const callbackRef = useRef(formStateToStore);
  useEffect(() => {
    callbackRef.current = formStateToStore;
  });

  const debouncedFn = useRef(
    debounce(
      (...args: any[]) => callbackRef.current(...args),
      DEBOUNCE_TIMEOUT,
    ),
  ).current;

  useEffect(() => {
    return () => {
      // Cancel any pending debounced calls on unmount.
      debouncedFn.cancel();
    };
  }, [debouncedFn]);

  return debouncedFn;
};
