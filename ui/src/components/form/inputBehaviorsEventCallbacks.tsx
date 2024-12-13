import type * as React from 'react';
import { parseValue } from '@/utils/function-utils';
import {
  getPropSchemas,
  jsonValidate,
  propInputData,
  toPropName,
} from '@/components/form/formUtil';
import type { InputUIData, PropsValues } from '@/types/Form';
import Ajv from 'ajv';
// @ts-ignore
import addDraft2019 from 'ajv-formats-draft2019';
import type { FormId } from '@/features/form/formStateSlice';
import {
  clearFieldError,
  setFieldError,
  setFieldValue,
} from '@/features/form/formStateSlice';
import type { AppDispatch } from '@/app/store';
const ajv = new Ajv();
addDraft2019(ajv);

/**
 * Determines if JSON Validation should be skipped.
 * Ideally, this function can be removed at some point. It's here because the
 * schema validation currently only works for props managed by one form element.
 *
 * @param {string} name
 *   The name attribute of the form element.
 * @param target
 *   The HTMLFormElement being validated.
 * @param {InputUIData} inputAndUiData
 *   An object usually generated on render in inputBehaviors.tsx.
 *   The specific properties required by this function:
 *   - selectedComponent {string}: the id of the selected component within the model.
 *
 * @return {boolean} true if JSON Validation should be skipped.
 */
const shouldSkipJsonValidation = (
  name: string,
  target: HTMLInputElement,
  inputAndUiData: InputUIData,
): boolean => {
  if (!(target.form instanceof HTMLFormElement)) {
    return true;
  }
  const { selectedComponent } = inputAndUiData;
  const formData = new FormData(target.form);
  const formState = Object.fromEntries(formData);
  const { multipleInputsSingleValue } = propInputData(
    formState,
    inputAndUiData,
  );

  if (multipleInputsSingleValue.includes(toPropName(name, selectedComponent))) {
    console.warn(
      `Input ${toPropName(name, selectedComponent)} is part of a single value prop that corresponds to multiple form fields. This is not yet supported and JSON Schema validation is skipped.`,
    );
    return true;
  }
  return false;
};

/**
 * Callback for the change event in a props form element.
 *
 * @param {React.ChangeEvent} e
 *   A change event
 * @param {attributes}  attributes
 *   The attributes object passed to the form element component
 * @param {InputUIData} inputAndUiData
 *   An object usually generated on render in inputBehaviors.tsx.
 *   The specific properties required by this function:
 *     - selectedComponent {string}: the id of the selected component within the model.
 *     - setInputValue {function}: React state updater for the input value.
 * @param {function} storeUpdateCallback
 *   The function created in inputBehaviors that updates the Redux store based on form state.
 * @param {function} dispatch
 *   Function dispatch.
 * @param {Record<string, any>} allValues
 *   Current values.
 */
export function inputBehaviorOnChange(
  e: React.ChangeEvent,
  attributes: PropsValues,
  inputAndUiData: InputUIData,
  storeUpdateCallback: (arg0: PropsValues) => void,
  dispatch: AppDispatch,
  allValues: Record<string, any>,
) {
  const { selectedComponent, setInputValue } = inputAndUiData;
  const target = e.target as HTMLInputElement | HTMLSelectElement;
  const schemas = getPropSchemas(inputAndUiData);
  const propName = toPropName(attributes.name, selectedComponent);
  const formId = attributes['data-form-id'] as FormId;
  if (formId) {
    dispatch(
      clearFieldError({
        formId,
        fieldName: attributes.name,
      }),
    );
  }

  // If parsing results in a number that is not NaN, return the number, otherwise return the original string
  const value = parseValue(
    target.value,
    target as HTMLInputElement,
    schemas?.[propName],
  );

  // Update the value of the input in the local state.
  if (setInputValue) {
    setInputValue(value);
  }
  // Update the value of the input in the Redux store.
  if (formId) {
    dispatch(
      setFieldValue({
        formId,
        fieldName: attributes.name,
        value,
      }),
    );
  }

  // Check if the input is valid before continuing.
  if (target instanceof HTMLInputElement && !target.reportValidity()) {
    return;
  }

  if (attributes.name && value) {
    if (
      target instanceof HTMLInputElement &&
      target.form instanceof HTMLFormElement
    ) {
      if (!shouldSkipJsonValidation(attributes.name, target, inputAndUiData)) {
        const [valid] = jsonValidate(propName, value, inputAndUiData);
        if (!valid) {
          return;
        }
      }
    }
  }

  // @todo this really belongs in a listener middleware?
  storeUpdateCallback({ ...allValues, [attributes.name]: value });
}

/**
 * Callback for the blur event in a props form element.
 *
 * @param {React.FocusEvent} e
 *   A change event
 * @param {attributes}  attributes
 *   The attributes object passed to the form element component
 * @param {InputUIData} inputAndUiData
 *   An object usually generated on render in inputBehaviors.tsx.
 *   The specific properties required by this function:
 *     - selectedComponent {string}: the id of the selected component within the model.
 *     - setInputMessages {function}: React state updater for input-specific messages.
 * @param {AppDispatch} dispatch
 *   Dispatch function.
 */
export function inputBehaviorOnBlur(
  e: React.FocusEvent,
  attributes: PropsValues,
  inputAndUiData: InputUIData,
  dispatch: AppDispatch,
) {
  const { selectedComponent } = inputAndUiData;
  const target = e.target as HTMLInputElement | HTMLSelectElement;
  const schemas = getPropSchemas(inputAndUiData);
  const propName = toPropName(attributes.name, selectedComponent);
  const formId = attributes['data-form-id'] as FormId;
  const value = parseValue(
    target.value,
    target as HTMLInputElement,
    schemas?.[propName],
  );
  if (attributes.name && value) {
    if (
      target instanceof HTMLInputElement &&
      target.form instanceof HTMLFormElement
    ) {
      if (!shouldSkipJsonValidation(attributes.name, target, inputAndUiData)) {
        const [valid, validate] = jsonValidate(
          toPropName(attributes.name, selectedComponent),
          value,
          inputAndUiData,
        );
        if (!valid) {
          if (validate?.errors && formId) {
            dispatch(
              setFieldError({
                type: 'error',
                message: ajv.errorsText(validate.errors),
                formId,
                fieldName: attributes.name,
              }),
            );
          }
        }
        return valid;
      }
    }

    return true;
  }
}
