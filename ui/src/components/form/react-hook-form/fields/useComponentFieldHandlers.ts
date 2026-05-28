import {
  validateNewValue as componentValidateNewValue,
  errorsText,
  parseNewValue,
} from './componentFieldValidation';
import { useFieldHandlers } from './useFieldHandlers';

import type * as ReactType from 'react';
import type { PropsValues } from '@drupal-canvas/types';
import type { useAppDispatch } from '@/app/hooks';
import type { InputUIData } from '@/types/Form';
import type { UseFieldHandlersResult } from './useFieldHandlers';

interface ComponentExtraRef {
  inputAndUiData: InputUIData;
  propName: string;
  selectedComponent: string;
  transforms: any;
  multipleInputsSingleValue: PropsValues;
  fieldName: string;
}

interface UseComponentFieldHandlersParams {
  fieldName: string;
  props: Record<string, any>;
  formState: PropsValues;
  dispatch: ReturnType<typeof useAppDispatch>;
  formContext: { formId: string } | null;
  inputAndUiData: InputUIData;
  propName: string;
  selectedComponent: string;
  transforms: any;
  multipleInputsSingleValue: PropsValues;
  updateLayoutModelStore: (newFormState: PropsValues) => void;
}

type UseComponentFieldHandlersResult =
  UseFieldHandlersResult<ComponentExtraRef>;

/**
 * Extracts a new prop value from a change event using the component form's
 * transform/parse logic. Reads domain-specific state from the latest ref
 * rather than closing over render-cycle variables.
 */
const extractComponentValue = (
  e: ReactType.ChangeEvent,
  latest?: ComponentExtraRef & Record<string, any>,
): any =>
  parseNewValue(
    e,
    latest!.inputAndUiData,
    latest!.propName,
    latest!.selectedComponent,
    latest!.transforms,
    latest!.multipleInputsSingleValue,
  );

/**
 * Validates a new prop value against its JSON Schema.
 * Reads domain-specific state from the latest ref.
 */
const validateComponentValue = (
  e: ReactType.ChangeEvent,
  newValue: any,
  latest?: ComponentExtraRef & Record<string, any>,
) =>
  componentValidateNewValue(
    e,
    newValue,
    latest!.fieldName,
    latest!.selectedComponent,
    latest!.inputAndUiData,
  );

/**
 * Encapsulates the stable onChange/onBlur callbacks and FieldContext value for
 * a component form field.
 */
export function useComponentFieldHandlers({
  fieldName,
  props,
  formState,
  dispatch,
  formContext,
  inputAndUiData,
  propName,
  selectedComponent,
  transforms,
  multipleInputsSingleValue,
  updateLayoutModelStore,
}: UseComponentFieldHandlersParams): UseComponentFieldHandlersResult {
  return useFieldHandlers<ComponentExtraRef>({
    fieldName,
    props,
    formState,
    dispatch,
    formContext,
    updateStore: updateLayoutModelStore,
    extraRefData: {
      inputAndUiData,
      propName,
      selectedComponent,
      transforms,
      multipleInputsSingleValue,
      fieldName,
    },
    extractNewValue: extractComponentValue,
    validateNewValue: validateComponentValue,
    errorsText,
  });
}
