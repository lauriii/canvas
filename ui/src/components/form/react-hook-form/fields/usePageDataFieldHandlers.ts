import {
  errorsText,
  validateNewValue as pageDataValidateNewValue,
} from './pageDataFieldValidation';
import { extractPageDataValue } from './pageDataFormHandlers';
import { useFieldHandlers } from './useFieldHandlers';

import type * as ReactType from 'react';
import type { PropsValues } from '@drupal-canvas/types';
import type { useAppDispatch } from '@/app/hooks';
import type { UseFieldHandlersResult } from './useFieldHandlers';

interface UsePageDataFieldHandlersParams {
  fieldName: string;
  props: Record<string, any>;
  formState: PropsValues;
  dispatch: ReturnType<typeof useAppDispatch>;
  formContext: { formId: string } | null;
  updatePageDataStore: (newFormState: PropsValues) => void;
}

type UsePageDataFieldHandlersResult = UseFieldHandlersResult;

/**
 * Wraps pageDataValidateNewValue with the three-argument signature expected by
 * useFieldHandlers. The `latest` ref argument is unused here because page-data
 * validation relies only on the event and its value.
 */
const validatePageDataValue = (
  e: ReactType.ChangeEvent,
  newValue: any,
  _latest: Record<string, any>,
) => pageDataValidateNewValue(e, newValue);

/**
 * Encapsulates the stable onChange/onBlur callbacks and FieldContext value for
 * a page data form field.
 */
export function usePageDataFieldHandlers({
  fieldName,
  props,
  formState,
  dispatch,
  formContext,
  updatePageDataStore,
}: UsePageDataFieldHandlersParams): UsePageDataFieldHandlersResult {
  return useFieldHandlers({
    fieldName,
    props,
    formState,
    dispatch,
    formContext,
    updateStore: updatePageDataStore,
    extractNewValue: extractPageDataValue,
    validateNewValue: validatePageDataValue,
    errorsText,
  });
}
