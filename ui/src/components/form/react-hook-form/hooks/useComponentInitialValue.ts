import { useEffect } from 'react';

import { dispatchFieldValue } from '@/components/form/react-hook-form/utils';

import type { useAppDispatch } from '@/app/hooks';
import type { FormId } from '@/features/form/formStateSlice';

/**
 * Custom hook to initialize field values in component instance forms.
 * Updates Redux store with the initial field value on mount.
 */
export const useComponentInitialValue = (
  dispatch: ReturnType<typeof useAppDispatch>,
  fieldName: string,
  formId: FormId | null,
  getCurrentValue: () => any,
  propName: string,
) => {
  useEffect(() => {
    if (!formId) return;

    const currentValue = getCurrentValue();

    // Update Redux store with initial field value
    dispatchFieldValue(dispatch, formId, fieldName, currentValue);
    // This intentionally has fewer dependencies - the useEffect exists to populate
    // the initial value after mounting, after which the value is managed by
    // react-hook-form.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dispatch, fieldName, propName]);
};
