import { useEffect } from 'react';

import { dispatchFieldValue } from '@/components/form/react-hook-form/utils';

import type { ComponentType } from 'react';
import type { useAppDispatch } from '@/app/hooks';
import type { FormId } from '@/features/form/formStateSlice';

/**
 * Custom hook to initialize field values in page data forms.
 * Updates Redux store with the initial field value on mount.
 */
export const usePageDataInitialValue = (
  dispatch: ReturnType<typeof useAppDispatch>,
  fieldName: string,
  formContext: { formId: FormId } | null,
  getCurrentValueFromRedux: () => any,
  WrappedComponent: ComponentType<any>,
) => {
  useEffect(() => {
    if (!formContext?.formId) return;

    const currentValue = getCurrentValueFromRedux();

    // Update Redux store with initial field value
    dispatchFieldValue(dispatch, formContext.formId, fieldName, currentValue);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dispatch, fieldName, formContext?.formId, WrappedComponent]);
};
