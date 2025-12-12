import { useEffect, useState } from 'react';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { getDefaultValue } from '@/components/form/formUtil';

import type * as React from 'react';

import './InputBehaviors.css';

import Ajv from 'ajv';

import InputDescription from '@/components/form/components/drupal/InputDescription';
import { InputBehaviorsComponentPropsForm } from '@/components/form/InputBehaviorsComponentPropsForm';
import { InputBehaviorsEntityForm } from '@/components/form/InputBehaviorsEntityForm';
import { FORM_TYPES } from '@/features/form/constants';
import {
  clearFieldError,
  selectFieldError,
  selectFormValues,
  setFieldError,
  setFieldValue,
} from '@/features/form/formStateSlice';
import { AJAX_UPDATE_FORM_BUILD_ID_EVENT } from '@/types/Ajax';
import { isAjaxing } from '@/utils/isAjaxing';

import type { ErrorObject } from 'ajv/dist/types';
import type { FormId } from '@/features/form/formStateSlice';
import type { AjaxUpdateFormBuildIdEvent } from '@/types/Ajax';
import type { Attributes } from '@/types/DrupalAttribute';
import type { PropsValues } from '@/types/Form';

const ajv = new Ajv();

// Helper function to dispatch field value updates
const dispatchFieldValue = (
  dispatch: ReturnType<typeof useAppDispatch>,
  formId: FormId,
  fieldName: string,
  value: any,
) => {
  dispatch(
    setFieldValue({
      formId,
      fieldName,
      value,
    }),
  );
};

// Helper function to dispatch field error updates
const dispatchFieldError = (
  dispatch: ReturnType<typeof useAppDispatch>,
  formId: FormId,
  fieldName: string,
  validationResult: ValidationResult,
) => {
  dispatch(
    setFieldError({
      type: 'error',
      message:
        validationResult.errorMessage ||
        ajv.errorsText(validationResult.errors),
      formId,
      fieldName,
    }),
  );
};

// Helper to check if initial dispatch should be skipped for certain element types
const shouldSkipInitialDispatch = (elementType: string, inputValue: any) =>
  (elementType === 'radios' && inputValue === '') || elementType === 'radio';

// Helper to check if hidden field value should be tracked
const shouldTrackHiddenValue = (
  elementType: string,
  fieldName: string,
  attributes: Attributes,
) =>
  !['hidden', 'submit'].includes(elementType) ||
  fieldName === 'form_build_id' ||
  attributes['data-track-hidden-value'];

// Helper to check if form state should be updated based on validation
const shouldUpdateFormState = (
  e: React.ChangeEvent,
  validationResult: ValidationResult,
) => {
  if (
    typeof e?.target?.hasAttribute === 'function' &&
    e.target.hasAttribute('data-canvas-no-update')
  ) {
    return false;
  }
  return validationResult.valid || validationResult.skipEarlyReturn;
};

export const POLLED_BACKGROUND_TIMEOUT = 1000;
export const DEBOUNCE_TIMEOUT = 400;
export const IMMEDIATE_TIMEOUT = 0;

type ValidationResult = {
  valid: boolean;
  errors?: null | ErrorObject[];
  errorMessage?: string;
  skipEarlyReturn?: boolean;
};

type InputBehaviorsForm = (
  OriginalInput: React.FC,
  props: React.ComponentProps<any>,
) => React.ReactElement;

interface InputProps {
  attributes: Attributes & {
    onChange: (e: React.ChangeEvent) => void;
    onBlur: (e: React.FocusEvent) => void;
  };
  options?: { [key: string]: string }[];
}

// Wraps all form elements to provide common functionality and handle committing
// the form state, parsing and validation of values.
export const InputBehaviorsCommon = ({
  OriginalInput,
  props,
  callbacks,
}: {
  OriginalInput: React.FC<InputProps>;
  props: {
    value: any;
    options?: { [key: string]: string }[];
    attributes: Attributes & {
      onChange: (e: React.ChangeEvent) => void;
      onBlur: (e: React.FocusEvent) => void;
    };
    description?: {
      content: string;
      attributes: Attributes;
    };
    descriptionDisplay?: 'before' | 'after' | 'invisible';
  };
  callbacks: {
    commitFormState: (newFormState: PropsValues) => void;
    parseNewValue: (newValue: React.ChangeEvent) => any;
    validateNewValue: (e: React.ChangeEvent, newValue: any) => ValidationResult;
  };
}) => {
  const {
    attributes,
    options,
    value,
    descriptionDisplay = 'before',
    description = false,
    ...passProps
  } = props;
  const { commitFormState, parseNewValue, validateNewValue } = callbacks;
  const dispatch = useAppDispatch();
  const defaultValue = getDefaultValue(options, attributes, value);
  const [inputValue, setInputValue] = useState(defaultValue || '');

  const formValues = useAppSelector((state) =>
    selectFormValues(state, attributes['data-form-id'] as FormId),
  );

  const formId = attributes['data-form-id'] as FormId;
  const fieldName = (attributes.name ||
    attributes['data-canvas-name']) as string;

  const fieldIdentifier = {
    formId,
    fieldName,
  };
  const fieldError = useAppSelector((state) =>
    selectFieldError(state, fieldIdentifier),
  );
  // Include the input's default value in the form state on init - including
  // when an element is added via AJAX.
  const elementType = attributes.type || attributes['data-canvas-type'];
  useEffect(() => {
    if (shouldSkipInitialDispatch(elementType as string, inputValue)) {
      return;
    }
    if (fieldName && formId) {
      dispatchFieldValue(
        dispatch,
        formId,
        fieldName,
        elementType === 'checkbox' ? !!inputValue : inputValue,
      );
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    // Special handling for the form_build_id which can be updated by an ajax
    // callback without using hyperscriptify to render a new React component.
    if (fieldName !== 'form_build_id') {
      return;
    }
    // Listen for changes to the form build ID so we can update that in
    // our form state and value.
    const formBuildIdListener = (e: AjaxUpdateFormBuildIdEvent) => {
      if (e.detail.formId === formId) {
        dispatchFieldValue(
          dispatch,
          formId,
          fieldName,
          e.detail.newFormBuildId,
        );
        setInputValue(e.detail.newFormBuildId);
      }
    };
    document.addEventListener(
      AJAX_UPDATE_FORM_BUILD_ID_EVENT,
      formBuildIdListener as unknown as EventListener,
    );
    return () => {
      document.removeEventListener(
        AJAX_UPDATE_FORM_BUILD_ID_EVENT,
        formBuildIdListener as unknown as EventListener,
      );
    };
  }, [dispatch, fieldName, formId, setInputValue]);

  // Don't track the value of hidden fields except for form_build_id or ones
  // with the 'data-track-hidden-value' attribute set.
  if (!shouldTrackHiddenValue(elementType as string, fieldName, attributes)) {
    attributes.readOnly = '';
  } else if (!attributes['data-drupal-uncontrolled']) {
    // If the input is not explicitly set as uncontrolled, its state should
    // be managed by React.
    attributes.value = inputValue;

    attributes.onChange = (e: React.ChangeEvent) => {
      delete attributes['data-invalid-prop-value'];

      const formId = attributes['data-form-id'] as FormId;
      if (formId) {
        dispatch(
          clearFieldError({
            formId,
            fieldName,
          }),
        );
      }

      const newValue = parseNewValue(e);
      // Update the value of the input in the local state.
      setInputValue(newValue);

      // Update the value of the input in the Redux store.
      if (formId) {
        dispatchFieldValue(dispatch, formId, fieldName, newValue);
      }

      // Check the current value against the JSON Schema definition for the
      // prop. If the value is invalid, we return early and skip updating the
      // store.
      if (
        fieldName &&
        (newValue || newValue === '') &&
        e.target instanceof HTMLInputElement &&
        e.target.form instanceof HTMLFormElement
      ) {
        const validationResult = validateNewValue(e, newValue);
        if (!shouldUpdateFormState(e, validationResult)) {
          if (formId) {
            dispatchFieldError(dispatch, formId, fieldName, validationResult);
          }
          return;
        }
      }

      // If no AJAX operations are in progress, update the form state and store.
      if (!isAjaxing()) {
        commitFormState({ ...formValues, [fieldName]: newValue });
        return;
      }
      // This is only reached if AJAX operations are in progress. Add an event
      // listener to update the form state once ajax is complete.
      const stopListener = () => {
        commitFormState({ ...formValues, [fieldName]: newValue });
      };
      document.addEventListener('drupalAjaxStop', stopListener, { once: true });
    };

    attributes.onBlur = (e: React.FocusEvent) => {
      const validationResult = validateNewValue(e, inputValue);
      if (!validationResult.valid) {
        if (formId) {
          attributes['data-invalid-prop-value'] = 'true';
          dispatchFieldError(dispatch, formId, fieldName, validationResult);
        }
      }
    };
  }

  // React objects to inputs with the value attribute set if there are no
  // event handlers added via on* attributes.
  const hasListener = Object.keys(attributes).some((key) =>
    /^on[A-Z]/.test(key),
  );

  // The value attribute can remain for hidden and submit inputs, but
  // otherwise dispose of `value`.
  if (!hasListener && !['hidden', 'submit'].includes(elementType as string)) {
    delete attributes.value;
  }

  return (
    <>
      <InputDescription
        description={description}
        descriptionDisplay={descriptionDisplay}
      >
        <OriginalInput
          {...passProps}
          attributes={attributes}
          options={options}
        />
        {fieldError && (
          <span data-prop-message>
            {`${fieldError.type === 'error' ? '❌ ' : ''}${fieldError.message}`}
          </span>
        )}
      </InputDescription>
    </>
  );
};

// Provides a higher order component to wrap a form element that will map to
// a more specific higher order component depending on the element's form ID.
const InputBehaviors = (OriginalInput: React.FC) => {
  const InputBehaviorsWrapper: React.FC<React.ComponentProps<any>> = (
    props,
  ) => {
    const { attributes } = props;
    const formId = attributes['data-form-id'] as FormId;
    const FORM_INPUT_BEHAVIORS: Record<FormId, InputBehaviorsForm> = {
      [FORM_TYPES.COMPONENT_INSTANCE_FORM]: InputBehaviorsComponentPropsForm,
      [FORM_TYPES.ENTITY_FORM]: InputBehaviorsEntityForm,
    };

    if (formId === undefined) {
      // This is not one of the forms we manage, e.g. the media library form
      // popup.
      return <OriginalInput {...props} />;
    }
    if (!(formId in FORM_INPUT_BEHAVIORS)) {
      throw new Error(`No input behavior defined for form ID: ${formId}`);
    }
    return FORM_INPUT_BEHAVIORS[formId](OriginalInput, props);
  };

  return InputBehaviorsWrapper;
};

export default InputBehaviors;
