import { useEffect, useCallback, useState } from 'react';
import type * as React from 'react';
import { selectLatestUndoRedoActionId } from '@/features/ui/uiSlice';
import {
  getDefaultValue,
  validateProp,
  toPropName,
  getPropSchemas,
  shouldSkipPropValidation,
  getPropsValues,
  propInputData,
} from '@/components/form/formUtil';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import type {
  ComponentModels,
  ResolvedValues,
  Sources,
} from '@/features/layout/layoutModelSlice';
import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import {
  isEvaluatedComponentModel,
  selectLayout,
  selectModel,
} from '@/features/layout/layoutModelSlice';
import { parseValue, flaggedForRemoval } from '@/utils/function-utils';
import { debounce } from 'lodash';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import './InputBehaviors.css';
import type { PropsValues, InputUIData } from '@/types/Form';

import type { Attributes } from '@/types/DrupalAttribute';
import Ajv from 'ajv';
// @ts-ignore
import addDraft2019 from 'ajv-formats-draft2019';
import {
  selectPageData,
  setPageData,
  externalUpdateComplete,
} from '@/features/pageData/pageDataSlice';
import type { FormId } from '@/features/form/formStateSlice';
import { selectCurrentComponent } from '@/features/form/formStateSlice';
import {
  selectFieldError,
  selectFormValues,
  setFieldError,
  setFieldValue,
  clearFieldError,
} from '@/features/form/formStateSlice';
import type { ErrorObject } from 'ajv/dist/types';
import type { XBComponent } from '@/types/Component';
import { componentHasFieldData } from '@/types/Component';
import { FORM_TYPES } from '@/features/form/constants';
import { useUpdateComponentMutation } from '@/services/preview';
import type { AjaxUpdateFormBuildIdEvent } from '@/types/Ajax';
import { AJAX_UPDATE_FORM_BUILD_ID_EVENT } from '@/types/Ajax';
import { useComponentTransforms } from '@/components/DummyPropsEditForm';

const ajv = new Ajv();
addDraft2019(ajv);

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
  pageData,
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
  pageData?: Record<string, any>;
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
  const fieldName = (attributes.name || attributes['data-xb-name']) as string;

  // @todo this is page data specific and should probably be moved to
  //  EntityFormBehaviors in https://drupal.org/i/3535569.
  const forceUpdateInputValue = (fieldName: string, theNewValue: string) => {
    dispatch(externalUpdateComplete(fieldName));
    const syntheticEvent = {
      target: {
        name: fieldName,
        value: theNewValue,
      },
    } as unknown as React.ChangeEvent<HTMLInputElement>;

    // Ignore TS to avoid adding several properties that are not needed for the
    // way that the onChange handler is used.
    // @ts-ignore
    attributes?.onChange?.(syntheticEvent);
    setInputValue(theNewValue);
  };

  // @todo this is page data specific and should probably be moved to
  //  EntityFormBehaviors in https://drupal.org/i/3535569.
  if (
    pageData &&
    pageData?.externalUpdates &&
    pageData.externalUpdates.includes(fieldName) &&
    pageData[fieldName]
  ) {
    setTimeout(() => {
      forceUpdateInputValue(fieldName, pageData[fieldName]);
    });
  }

  const fieldIdentifier = {
    formId,
    fieldName,
  };
  const fieldError = useAppSelector((state) =>
    selectFieldError(state, fieldIdentifier),
  );
  // Include the input's default value in the form state on init - including
  // when an element is added via AJAX.
  const elementType = attributes.type || attributes['data-xb-type'];
  useEffect(() => {
    if (
      // Ignore radios in indeterminate (initial unset) state.
      // @see https://developer.mozilla.org/en-US/docs/Web/API/HTMLInputElement/indeterminate
      (elementType === 'radios' && inputValue === '') ||
      // Every individual radio element has a value, but it isn't
      // the value of the field unless it is checked. The value of the field is
      // managed by the radios group, not the individual radio elements.
      elementType === 'radio'
    ) {
      return;
    }
    if (fieldName && formId) {
      dispatch(
        setFieldValue({
          formId,
          fieldName,
          value: elementType === 'checkbox' ? !!inputValue : inputValue,
        }),
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
        dispatch(
          setFieldValue({
            formId,
            fieldName,
            value: e.detail.newFormBuildId,
          }),
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

  // Use debounce to prevent excessive repaints of the layout.
  const debounceStoreUpdate = debounce(
    commitFormState,
    ['checkbox', 'radio'].includes(elementType as string) ? 0 : 400,
  );

  // Register the debounced store function as a callback so debouncing is
  // preserved between renders.
  const storeUpdateCallback = useCallback(
    (value: PropsValues) => debounceStoreUpdate(value),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  );

  // Don't track the value of hidden fields except for form_build_id or ones
  // with the 'data-track-hidden-value' attribute set.
  if (
    ['hidden', 'submit'].includes(elementType as string) &&
    fieldName !== 'form_build_id' &&
    !attributes['data-track-hidden-value']
  ) {
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

      // The data-xb-no-update indicates we should return early and not update the
      // store.
      if (
        typeof e?.target?.hasAttribute === 'function' &&
        e.target.hasAttribute('data-xb-no-update')
      ) {
        return;
      }
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
        const inputElement = e.target;
        const requiredAndOnlyProblemIsEmpty =
          inputElement.required &&
          Object.keys(inputElement.validity).every(
            (validityProperty: string) =>
              ['valid', 'valueMissing'].includes(validityProperty)
                ? inputElement.validity[validityProperty as keyof ValidityState]
                : !inputElement.validity[
                    validityProperty as keyof ValidityState
                  ],
          );
        // We will return early unless the only problem caught by native
        // validation is a required field that is empty.
        if (!requiredAndOnlyProblemIsEmpty) {
          return;
        }
      }

      if (
        fieldName &&
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
  if (!hasListener && !['hidden', 'submit'].includes(elementType as string)) {
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
// component inputs form.
const InputBehaviorsComponentPropsForm = (
  OriginalInput: React.FC,
  props: React.ComponentProps<any>,
): React.ReactElement => {
  /**
   * @todo #3502484 useParams() should be used here to replace getting the value from currentComponent in the formStateSlice
   * Hyperscriptify re-creates the React component for the media library when Drupal ajax completes does not wrap the
   * rendering in the correct React Router context so we can't get the selected component ID from the url in inputBehaviors.tsx.
   * We already have a workaround for this for the Redux provider, could we do the same for the React Router context?
   */
  const currentComponent = useAppSelector(selectCurrentComponent);
  const selectedComponent = currentComponent || 'noop';
  const model = useAppSelector(selectModel);
  const { attributes } = props;
  const { data: components } = useGetComponentsQuery();
  const transforms = useComponentTransforms();
  const layout = useAppSelector(selectLayout);
  const node = findComponentByUuid(layout, selectedComponent);
  const [selectedComponentType, version] = (
    node ? (node.type as string) : 'noop'
  ).split('@');
  const inputAndUiData: InputUIData = {
    selectedComponent,
    components,
    selectedComponentType,
    layout,
    node,
    version,
    model,
  };
  const component = components?.[selectedComponentType];

  const [patchComponent] = useUpdateComponentMutation({
    fixedCacheKey: selectedComponent,
  });

  const formStateToStore = (newFormState: PropsValues) => {
    // Apply (client-side) transforms for form state.
    const { propsValues: values, selectedModel } = getPropsValues(
      newFormState,
      inputAndUiData,
      transforms,
    );

    // And then send data to backend - this will:
    // a) Trigger server-side validation/transformation (massaging of widget values)
    // b) Update both the preview and the model - see the pessimistic update
    //    in onQueryStarted in preview.ts
    // @see \Drupal\Core\Field\WidgetInterface::massageFormValues()
    const resolved = { ...selectedModel.resolved, ...values };

    // Check the object for any values that are flagged for removal. Note that
    // removal flagging is not necessary for all prop types. It is used for
    // props with complex prop shapes where the empty-indicating value is nested
    // with the structure.
    Object.keys(values).forEach((prop) => {
      if (flaggedForRemoval(values[prop]) && component?.propSources?.[prop]) {
        // If the prop is optional, it can be removed.
        if (!component.propSources[prop]?.required) {
          if (isEvaluatedComponentModel(selectedModel)) {
            // The source value can also be updated to empty when permitted.
            if (!Object.isFrozen(selectedModel.source[prop])) {
              selectedModel.source[prop].value = [];
            }
          }
          resolved[prop] = [];
        } else {
          // If the prop is required, we need to set it back to the default.
          resolved[prop] = component.propSources[prop].default_values.resolved;
        }
      }
    });

    if (isEvaluatedComponentModel(selectedModel) && component) {
      patchComponent({
        componentInstanceUuid: selectedComponent,
        componentType: `${selectedComponentType}@${version}`,
        model: {
          source: syncPropSourcesToResolvedValues(
            selectedModel.source,
            component,
            resolved,
          ),
          resolved,
        },
      });
      return;
    }
    patchComponent({
      componentInstanceUuid: selectedComponent,
      componentType: `${selectedComponentType}@${version}`,
      model: {
        ...selectedModel,
        resolved,
      },
    });
  };

  const formState = useAppSelector((state) =>
    selectFormValues(state, FORM_TYPES.COMPONENT_INPUTS_FORM),
  );

  const fieldName = attributes.name || attributes['data-xb-name'];
  const propName = toPropName(fieldName, selectedComponent);
  const propsOverrides: { options?: Object[] } = {};

  const { multipleInputsSingleValue } = propInputData(
    formState,
    inputAndUiData,
  );

  const parseNewValue = (e: React.ChangeEvent) => {
    const schemas = getPropSchemas(inputAndUiData);
    const rawValue = parseValue(
      (e.target as HTMLInputElement | HTMLSelectElement).value,
      e.target as HTMLInputElement,
      schemas?.[propName],
    );
    const fieldName = (e.target as HTMLInputElement | HTMLSelectElement).name;
    if (
      // If there are no transforms, we cannot use them, just return the raw
      // value. Note that the 'undefined' check here is technically not required
      // because at this point the form has loaded and the value will be
      // defined, it is required to satisfy type-checks.
      transforms === undefined ||
      Object.entries(transforms).length === 0 ||
      // Or if there are no transforms for this prop, don't bother with the
      // overhead of transforms.
      !(propName in transforms) ||
      // Or if the prop relies on multiple input fields.
      multipleInputsSingleValue.includes(propName)
    ) {
      return rawValue;
    }
    const { propsValues: values } = getPropsValues(
      { [fieldName]: rawValue },
      inputAndUiData,
      transforms,
    );
    return propName in values ? values[propName] : rawValue;
  };

  const validateNewValue = (e: React.ChangeEvent, newValue: any) => {
    const target = e.target as HTMLInputElement;
    if (
      !shouldSkipPropValidation(fieldName, target, inputAndUiData, newValue)
    ) {
      const [valid, validate] = validateProp(
        toPropName(fieldName, selectedComponent),
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

  if (props.options && !props.attributes.required) {
    // If an element has a `_none` value as one of the render array options,
    // and there is no value stored for this prop, then we set that _none as the
    // selected option. This logic is only necessary in the component instance
    // form, hence it being located here.
    if (
      props.options.some((option: PropsValues) => option.value === '_none') &&
      !inputAndUiData?.model?.[currentComponent as keyof ComponentModels]
        .resolved[propName]
    ) {
      propsOverrides.options = props.options.map((option: PropsValues) =>
        option.value === '_none'
          ? { ...option, selected: true }
          : { ...option, selected: false },
      );
    }
  }

  return (
    <InputBehaviorsCommon
      OriginalInput={OriginalInput}
      props={{ ...props, ...propsOverrides }}
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
  const formState = useAppSelector((state) =>
    selectFormValues(state, FORM_TYPES.ENTITY_FORM),
  );

  const { attributes } = props;
  const fieldName = attributes.name || attributes['data-xb-name'];
  if (!['changed', 'externalUpdates'].includes(fieldName)) {
    let newValue = pageData[fieldName] || null;

    if (attributes.name === 'form_build_id' && 'form_build_id' in formState) {
      // We always take the latest form_build_id value from form state.
      // We have an event listener in the generic inputBehaviors to react to
      // the update_build_id Ajax command, but that event can fire while the
      // input is not yet mounted, which can result in a stale form_build_id
      // being used.
      newValue = formState.form_build_id;
    }

    // @todo Handle the revision form elements on nodes.
    // @todo Handle `date` and `time` inputs.

    const elementType = attributes.type || attributes['data-xb-type'];
    if (!['radio', 'hidden', 'submit'].includes(elementType as string)) {
      attributes.value = newValue;
    }
    if (elementType === 'checkbox') {
      if (typeof newValue === 'undefined' || newValue === null) {
        attributes.checked = !!attributes?.checked;
      } else {
        attributes.checked = Boolean(Number(newValue));
      }
    }
  }

  const formStateToStore = (newFormState: PropsValues) => {
    const values = Object.keys(newFormState).reduce(
      (acc: Record<string, any>, key) => {
        if (
          !['changed', 'formId', 'formType', 'externalUpdates'].includes(key)
        ) {
          return { ...acc, [key]: newFormState[key] };
        }
        return acc;
      },
      {},
    );
    // Flag that we need to update the preview.
    dispatch(setUpdatePreview(true));
    dispatch(setPageData(values));
  };

  const parseNewValue = (e: React.ChangeEvent) => {
    const target = e.target as HTMLInputElement;
    // If the target is an input element, return its value
    if (target.value !== undefined) {
      // We have a special case for `_none`, which represents an empty value in a
      // select element. It is converted to an empty string so it can leverage
      // the logic for textfields where an empty string results in the prop
      // being removed from the model.
      return target.value === '_none' ? null : target.value;
    }
    // If the target is a checkbox or radio button, return its checked
    if ('checked' in target) {
      return target.checked;
    }
    // If the target is neither an input element nor a checkbox/radio button, return null
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
      pageData={pageData}
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
      [FORM_TYPES.COMPONENT_INPUTS_FORM]: InputBehaviorsComponentPropsForm,
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

export const syncPropSourcesToResolvedValues = (
  sources: Sources,
  component: XBComponent,
  resolvedValues: ResolvedValues,
): Sources => {
  if (!componentHasFieldData(component)) {
    return sources;
  }
  const fieldData = component.propSources;

  // We need to include a source entry for any props with a resolved value.
  // We don't store a source entry for empty values, so once the value is no
  // longer empty we need to populate the source data for it from the
  // prop source defaults for this component.
  const missingProps = Object.keys(fieldData).filter(
    (key) => !(key in sources) && Object.keys(resolvedValues).includes(key),
  );

  // Likewise, if a resolved value is now empty, we need to remove it from
  // the source data so it is not evaluated server side.
  const emptyProps = Object.keys(fieldData).filter(
    (key) => !Object.keys(resolvedValues).includes(key) && key in sources,
  );

  return missingProps.reduce(
    (carry: Sources, propName: string) => ({
      ...carry,
      // Add in the missing source.
      [propName]: fieldData[propName],
    }),
    Object.entries(sources).reduce((carry: Sources, [propName, source]) => {
      if (emptyProps.includes(propName)) {
        // Ignore this source as the value is now empty.
        return carry;
      }
      return {
        ...carry,
        [propName]: {
          ...source,
          // Set the value from resolved values. This might duplicate the value
          // in the resolved key for components where the source and resolved
          // values are the same, however this method is generally called before
          // a patchComponent request to Drupal which will remove values from
          // the source key if it duplicates the resolved value. So for a simple
          // component with e.g. a string property, we would have duplication
          // here but this would be removed from the model returned from Drupal
          // during patchComponent and hence the model stored in the redux store
          // after this request. For a component with an expression such as an
          // image component - at this point both resolved and source may be a
          // media entity ID. When patchComponent is called in that instance,
          // Drupal will retain the media entity ID in the source value, but
          // return the evaluated expression for the resolved values - e.g. this
          // might be the src, alt, height and width for the media entity.
          value: resolvedValues[propName],
        },
      };
    }, {}),
  );
};
