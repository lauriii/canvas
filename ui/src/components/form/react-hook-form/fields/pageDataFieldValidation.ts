import { createAjv } from '@/utils/ajv';

import type * as ReactType from 'react';

const ajv = createAjv();

/**
 * Formats an Ajv errors array into a human-readable string.
 * Kept here so callers don't need their own Ajv instance just to format errors.
 */
export const errorsText = (errors?: any) => ajv.errorsText(errors || null);

/**
 * Validates a page data form input using the HTML5 validation API.
 *
 * For the page data form we rely on native browser validation rather than JSON
 * Schema, but surface errors through the same FieldErrorDisplay component used
 * by the component instance form.
 *
 * Returns `skipEarlyReturn: true` when the only problem is a missing value on a
 * required field, so the auto-save is not blocked while the user is still
 * typing into an empty field.
 */
export const validateNewValue = (e: ReactType.ChangeEvent, newValue: any) => {
  if (!(e.target instanceof HTMLInputElement)) {
    return { valid: true, errors: null };
  }

  if (!e.target.checkValidity()) {
    const inputElement = e.target;
    const requiredAndOnlyProblemIsEmpty =
      inputElement.required &&
      Object.keys(inputElement.validity).every((validityProperty: string) =>
        ['valueMissing'].includes(validityProperty)
          ? inputElement.validity[validityProperty as keyof ValidityState]
          : !inputElement.validity[validityProperty as keyof ValidityState],
      );
    return {
      valid: false,
      errorMessage: e.target.validationMessage,
      skipEarlyReturn: requiredAndOnlyProblemIsEmpty,
    };
  }

  return { valid: true, errors: null };
};

/**
 * Creates a react-hook-form validation function using the HTML5 validation API.
 *
 * Copies the relevant HTML5 constraint attributes onto a temporary input element
 * and checks its validity, returning the browser's own validation message on
 * failure so contributors don't need to maintain custom error strings.
 *
 * @example
 * rules={{ validate: { html5Validation: createHtml5Validator(props.attributes) } }}
 */
export function createHtml5Validator(attrs: Record<string, any>) {
  return (value: any): true | string => {
    const tempInput = document.createElement('input');

    // HTML5 validation attributes to mirror onto the temporary element.
    const validationAttrs = [
      'type',
      'required',
      'pattern',
      'min',
      'max',
      'minLength',
      'maxLength',
    ];
    validationAttrs.forEach((attr) => {
      if (attrs[attr] !== undefined) {
        (tempInput as any)[attr] = attrs[attr];
      }
    });

    tempInput.value = value || '';

    return tempInput.checkValidity() ? true : tempInput.validationMessage;
  };
}
