import { useEffect, useCallback, useState } from 'react';
import type * as React from 'react';
import { selectSelectedComponent } from '@/features/ui/uiSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectLayout,
  selectModel,
  updateNodeModelForce,
} from '@/features/layout/layoutModelSlice';
import { debounce } from 'lodash';
import { useGetComponentsQuery } from '@/services/components';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import './InputBehaviors.css';
import type { PropsValues, InputUIData } from '@/types/Form';
import { getDefaultValue, getPropsValues } from '@/components/form/formUtil';
import {
  inputBehaviorOnChange,
  inputBehaviorOnBlur,
} from '@/components/form/inputBehaviorsEventCallbacks';
import type { FormId } from '@/features/form/formStateSlice';
import {
  selectFieldError,
  selectFormValues,
  setFieldValue,
} from '@/features/form/formStateSlice';

// Wraps all form elements to provide common functionality and subscribe to the
// parent form's context.
const InputBehaviors = (OriginalInput: React.FC) => {
  function WrappedInput(
    properties: React.ComponentProps<any>,
  ): React.ReactElement {
    const dispatch = useAppDispatch();
    const selectedComponent = useAppSelector(selectSelectedComponent) || 'noop';
    const model = useAppSelector(selectModel);
    const selectedModel = { ...model[selectedComponent] };
    const { attributes, options, value, ...passProps } = properties;
    const defaultValue = getDefaultValue(options, attributes, value);
    const [inputValue, setInputValue] = useState(defaultValue || '');
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
      inputValue,
      setInputValue,
    };
    const formValues = useAppSelector((state) =>
      selectFormValues(state, attributes['data-form-id']),
    );
    const formId = attributes['data-form-id'] as FormId;
    const fieldIdentifier = {
      formId,
      fieldName: attributes.name,
    };
    const fieldError = useAppSelector((state) =>
      selectFieldError(state, fieldIdentifier),
    );
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
      } else if (attributes.name && formId) {
        dispatch(
          setFieldValue({
            formId,
            fieldName: attributes.name,
            value: attributes.type === 'checkbox' ? !!inputValue : inputValue,
          }),
        );
      }
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Use debounce to prevent excessive repaints of the layout.
    const debounceStoreUpdate = debounce(formStateToStore, 400);

    // Register the debounced store function as a callback so debouncing is
    // preserved between renders.
    const storeUpdateCallback = useCallback(
      (value: PropsValues) => debounceStoreUpdate(value),
      // eslint-disable-next-line react-hooks/exhaustive-deps
      [],
    );

    if (['hidden', 'submit'].includes(attributes.type)) {
      attributes.readOnly = '';
    } else if (!attributes['data-drupal-uncontrolled']) {
      // If the input is not explicitly set as uncontrolled, its state should
      // be managed by React.
      attributes.value = inputValue;
      attributes.onChange = (e: React.ChangeEvent) => {
        delete attributes['data-invalid-prop-value'];
        inputBehaviorOnChange(
          e,
          attributes,
          inputAndUiData,
          storeUpdateCallback,
          dispatch,
          formValues,
        );
      };
      attributes.onBlur = (e: React.FocusEvent) => {
        const valid = inputBehaviorOnBlur(
          e,
          attributes,
          inputAndUiData,
          dispatch,
        );
        if (!valid) {
          attributes['data-invalid-prop-value'] = 'true';
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
    if (!hasListener && !['hidden', 'submit'].includes(attributes.type)) {
      delete attributes.value;
    }

    return (
      <>
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
      </>
    );
  }
  return WrappedInput;
};

export default InputBehaviors;
