import { useEffect, useCallback, useState } from 'react';
import type * as React from 'react';
import {
  selectSelectedComponent,
  selectLatestUndoRedoActionId,
} from '@/features/ui/uiSlice';
import {
  getDefaultValue,
  getNameAttributeBasedOnId,
  jsonValidate,
  toPropName,
} from '@/components/form/formUtil';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectLayout,
  selectModel,
  updateNodeModelForce,
} from '@/features/layout/layoutModelSlice';
import { parseValue } from '@/utils/function-utils';
import { debounce } from 'lodash';
import { useGetComponentsQuery } from '@/services/components';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import './InputBehaviors.css';
import type { PropsValues, InputUIData } from '@/types/Form';
import {
  getPropsValues,
  getPropSchemas,
  shouldSkipJsonValidation,
} from '@/components/form/formUtil';
import type { Attributes } from '@/types/DrupalAttribute';
import Ajv from 'ajv';
// @ts-ignore
import addDraft2019 from 'ajv-formats-draft2019';
import { selectPageData, setPageData } from '@/features/pageData/pageDataSlice';
import type { FormId } from '@/features/form/formStateSlice';
import {
  selectFieldError,
  selectFormValues,
  setFieldError,
  setFieldValue,
  clearFieldError,
} from '@/features/form/formStateSlice';
import type { ErrorObject } from 'ajv/dist/types';

const ajv = new Ajv();
addDraft2019(ajv);

export const FORM_TYPES = {
  COMPONENT_PROPS_FORM: 'component_inputs_form' as const,
  BLOCK_FORM: 'block_form' as const,
  ENTITY_FORM: 'page_data_form' as const,
};

type ValidationResult = {
  valid: boolean;
  errors: null | ErrorObject[];
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
const InputBehaviorsCommon = ({
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
  };
  callbacks: {
    commitFormState: (newFormState: PropsValues) => void;
    parseNewValue: (newValue: React.ChangeEvent) => any;
    validateNewValue: (e: React.ChangeEvent, newValue: any) => ValidationResult;
  };
}) => {
  const { attributes, options, value, ...passProps } = props;
  const { commitFormState, parseNewValue, validateNewValue } = callbacks;
  const dispatch = useAppDispatch();
  const defaultValue = getDefaultValue(options, attributes, value);
  const [inputValue, setInputValue] = useState(defaultValue || '');

  const formValues = useAppSelector((state) =>
    selectFormValues(state, attributes['data-form-id'] as FormId),
  );

  const formId = attributes['data-form-id'] as FormId;
  const fieldName = attributes.name as string;
  const fieldIdentifier = {
    formId,
    fieldName,
  };
  const fieldError = useAppSelector((state) =>
    selectFieldError(state, fieldIdentifier),
  );

  // Include the input's default value in the form state on init - including
  // when an element is added via AJAX.
  useEffect(() => {
    if (attributes.name && formId) {
      dispatch(
        setFieldValue({
          formId,
          fieldName,
          value: attributes.type === 'checkbox' ? !!inputValue : inputValue,
        }),
      );
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Use debounce to prevent excessive repaints of the layout.
  const debounceStoreUpdate = debounce(
    commitFormState,
    ['checkbox', 'radio'].includes(attributes.type as string) ? 0 : 400,
  );

  // Register the debounced store function as a callback so debouncing is
  // preserved between renders.
  const storeUpdateCallback = useCallback(
    (value: PropsValues) => debounceStoreUpdate(value),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  );

  if (['hidden', 'submit'].includes(attributes.type as string)) {
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
        dispatch(
          setFieldValue({
            formId,
            fieldName,
            value: newValue,
          }),
        );
      }

      // Check if the input is valid before continuing.
      if (e.target instanceof HTMLInputElement && !e.target.reportValidity()) {
        return;
      }

      if (
        attributes.name &&
        newValue &&
        e.target instanceof HTMLInputElement &&
        e.target.form instanceof HTMLFormElement
      ) {
        if (!validateNewValue(e, newValue).valid) {
          return;
        }
      }

      storeUpdateCallback({ ...formValues, [fieldName]: newValue });
    };

    attributes.onBlur = (e: React.FocusEvent) => {
      const validationResult = validateNewValue(e, inputValue);
      if (!validationResult.valid) {
        if (formId) {
          attributes['data-invalid-prop-value'] = 'true';
          dispatch(
            setFieldError({
              type: 'error',
              message: ajv.errorsText(validationResult.errors),
              formId,
              fieldName,
            }),
          );
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
  if (
    !hasListener &&
    !['hidden', 'submit'].includes(attributes.type as string)
  ) {
    delete attributes.value;
  }

  return (
    <>
      <OriginalInput {...passProps} attributes={attributes} options={options} />
      {fieldError && (
        <span data-prop-message>
          {`${fieldError.type === 'error' ? '❌ ' : ''}${fieldError.message}`}
        </span>
      )}
    </>
  );
};

// Provides a higher order component to wrap a form element that is part of the
// component props form.
const InputBehaviorsComponentPropsForm = (
  OriginalInput: React.FC,
  props: React.ComponentProps<any>,
): React.ReactElement => {
  const dispatch = useAppDispatch();
  const selectedComponent = useAppSelector(selectSelectedComponent) || 'noop';
  const model = useAppSelector(selectModel);
  const selectedModel = { ...model[selectedComponent] };
  const { attributes } = props;
  const { data: components } = useGetComponentsQuery();
  const layout = useAppSelector(selectLayout);
  const node = findComponentByUuid(layout, selectedComponent);
  const selectedComponentType = node ? (node.type as string) : 'noop';
  const inputAndUiData: InputUIData = {
    selectedComponent,
    components,
    selectedComponentType,
    layout,
    node,
    model,
  };
  const formStateToStore = (newFormState: PropsValues) => {
    const { propsValues, selectedModel } = getPropsValues(
      newFormState,
      inputAndUiData,
    );
    dispatch(
      updateNodeModelForce({
        uuid: selectedComponent,
        model: { ...selectedModel, ...propsValues },
      }),
    );
  };

  // Include the input's default value in the form state on init - including
  // when an element is added via AJAX.
  useEffect(() => {
    const isMediaPreview =
      attributes['data-media-file'] && attributes['data-media-field-name'];
    if (isMediaPreview) {
      // @todo this is assuming the media is an image. This will eventually
      //  need to accommodate all media types.
      // @see media_library_storage_prop_shape_alter()
      // @see experience_builder_preprocess_media_library_item__widget()
      const image = JSON.parse(attributes['data-media-file']);
      image.width = Number(image.width);
      image.height = Number(image.height);
      dispatch(
        updateNodeModelForce({
          uuid: selectedComponent,
          model: {
            ...selectedModel,
            image,
          },
        }),
      );
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const parseNewValue = (e: React.ChangeEvent) => {
    const schemas = getPropSchemas(inputAndUiData);
    const propName = toPropName(attributes.name, selectedComponent);
    return parseValue(
      (e.target as HTMLInputElement | HTMLSelectElement).value,
      e.target as HTMLInputElement,
      schemas?.[propName],
    );
  };

  const validateNewValue = (e: React.ChangeEvent, newValue: any) => {
    const target = e.target as HTMLInputElement;
    if (!shouldSkipJsonValidation(attributes.name, target, inputAndUiData)) {
      const [valid, validate] = jsonValidate(
        toPropName(attributes.name, selectedComponent),
        newValue,
        inputAndUiData,
      );
      return {
        valid,
        errors: validate?.errors || null,
      };
    }
    return { valid: true, errors: null };
  };

  return (
    <InputBehaviorsCommon
      OriginalInput={OriginalInput}
      props={props}
      callbacks={{
        commitFormState: formStateToStore,
        parseNewValue,
        validateNewValue,
      }}
    />
  );
};

// Provides a higher order component to wrap a form element that is part of the
// entity fields form.
const InputBehaviorsEntityForm = (
  OriginalInput: React.FC,
  props: React.ComponentProps<any>,
): React.ReactElement => {
  const dispatch = useAppDispatch();
  const pageData = useAppSelector(selectPageData);
  const latestUndoRedoActionId = useAppSelector(selectLatestUndoRedoActionId);

  const { attributes } = props;

  if (
    !['form_id', 'form_build_id', 'form_token', 'changed'].includes(
      attributes.name,
    )
  ) {
    const newValue =
      pageData[attributes.name || getNameAttributeBasedOnId(attributes.id)] ||
      null;

    // @todo Handle the revision form elements on nodes.
    // @todo Handle media fields.
    // @todo Handle `date` and `time` inputs.

    if (!['radio', 'hidden', 'submit'].includes(attributes.type as string)) {
      attributes.value = newValue;
    }
    if (attributes.type === 'checkbox') {
      attributes.checked = !!newValue;
    }
  }

  const formStateToStore = (newFormState: PropsValues) => {
    const values = Object.keys(newFormState).reduce(
      (acc: Record<string, any>, key) => {
        if (
          !['changed', 'formId', 'formType'].includes(key) &&
          !key.startsWith('form_')
        ) {
          return { ...acc, [key]: newFormState[key] };
        }
        return acc;
      },
      {},
    );

    dispatch(setPageData(values));
  };

  const parseNewValue = (e: React.ChangeEvent) => {
    const target = e.target as HTMLInputElement;
    if (target.value) {
      return target.value;
    }
    if ('checked' in target) {
      return target.checked;
    }
    return null;
  };

  const validateNewValue = (e: React.ChangeEvent, newValue: any) => {
    // @todo Implement this.
    return { valid: true, errors: null };
  };

  return (
    <InputBehaviorsCommon
      key={`${attributes?.name}-${latestUndoRedoActionId}`}
      OriginalInput={OriginalInput}
      props={props}
      callbacks={{
        commitFormState: formStateToStore,
        parseNewValue,
        validateNewValue,
      }}
    />
  );
};

// Provides a higher order component to wrap a form element that is part of the
// block settings form.
// @todo Remove this in https://www.drupal.org/project/experience_builder/issues/3500152
const InputBehaviorsBlockSettingsForm = (
  OriginalInput: React.FC,
  props: React.ComponentProps<any>,
): React.ReactElement => {
  const dispatch = useAppDispatch();
  const selectedComponent = useAppSelector(selectSelectedComponent) || 'noop';
  const latestUndoRedoActionId = useAppSelector(selectLatestUndoRedoActionId);
  const { attributes } = props;

  const formStateToStore = (newFormState: PropsValues) => {
    const values = Object.keys(newFormState).reduce(
      (acc: Record<string, any>, key) => {
        if (!key.startsWith('form_')) {
          return { ...acc, [key]: newFormState[key] };
        }
        return acc;
      },
      {},
    );

    dispatch(
      updateNodeModelForce({
        uuid: selectedComponent,
        model: values,
      }),
    );
  };

  const parseNewValue = (e: React.ChangeEvent) => {
    const target = e.target as HTMLInputElement;
    if (target.value) {
      return target.value;
    }
    if ('checked' in target) {
      return target.checked;
    }
    return null;
  };

  const validateNewValue = (e: React.ChangeEvent, newValue: any) => {
    // @todo implement this in https://drupal.org/i/3500795
    return { valid: true, errors: null };
  };

  return (
    <InputBehaviorsCommon
      key={`${attributes?.name}-${latestUndoRedoActionId}`}
      OriginalInput={OriginalInput}
      props={props}
      callbacks={{
        commitFormState: formStateToStore,
        parseNewValue,
        validateNewValue,
      }}
    />
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
      [FORM_TYPES.COMPONENT_PROPS_FORM]: InputBehaviorsComponentPropsForm,
      [FORM_TYPES.BLOCK_FORM]: InputBehaviorsBlockSettingsForm,
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
