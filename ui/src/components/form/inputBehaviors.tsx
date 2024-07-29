import { useState, useContext, useEffect, useCallback } from 'react';
import type * as React from 'react';
import { FormDispatchContext } from './Form';
import { selectSelectedComponent } from '@/features/ui/uiSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectModel,
  updateNodeModel,
} from '@/features/layout/layoutModelSlice';
import { debounce } from 'lodash';

// Wraps all form elements to provide common functionality and subscribe to the
// parent form's context.
const InputBehaviors = (OriginalInput: React.FC) => {
  function WrappedInput(
    properties: React.ComponentProps<any>,
  ): React.ReactElement {
    const dispatch = useAppDispatch();
    const selectedComponent = useAppSelector(selectSelectedComponent) || 'noop';
    const model = useAppSelector(selectModel);
    const selectedModel = model[selectedComponent];
    const { attributes, ...passProps } = properties;
    const [inputValue, setInputValue] = useState(attributes.value || '');
    const setFormState = useContext(FormDispatchContext);

    const formStateToStore = (newFormState: object) => {
      // Get only the keys that correspond to SDC props.
      const keys = Object.keys(newFormState).filter((key) =>
        key.includes('xb_component_props['),
      );

      // Create an object with the prop names -> current value in form.
      const propsValues = keys.reduce((newObject: object, key: string) => {
        // Extract the prop name from the drupal-selector.
        // @todo: THIS CURRENTLY ONLY WORKS WITH PROPS WITH A SINGLE `value`
        //   PROPERTY!
        // Expand the supported prop shapes in https://www.drupal.org/i/3463842.
        const keyJustProp: string = key
          .replace(`xb_component_props[${selectedComponent}][`, '')
          .replace(/\].*$/, '');
        newObject[keyJustProp as keyof object] =
          newFormState[key as keyof object];
        return newObject;
      }, {});
      dispatch(
        updateNodeModel({
          uuid: selectedComponent,
          model: { ...selectedModel, ...propsValues },
        }),
      );
    };

    // Include the input's default value in the form state on init.
    useEffect(() => {
      if (attributes.name && setFormState) {
        setFormState((prior: object) => ({
          ...prior,
          [attributes.name]: inputValue,
        }));
      }
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Use debounce to prevent excessive repaints of the layout.
    const debounceStoreUpdate = debounce(formStateToStore, 400);

    // Register the debounced store function as a callback so debouncing is
    // preserved between renders.
    const storeUpdateCallback = useCallback(
      (value: object) => debounceStoreUpdate(value),
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
        const target = e.target as HTMLInputElement;
        // Update the value of the input - which belongs to just this instance
        // of inputBehaviors.
        setInputValue(target.value);

        // In addition, update the Context-stored Form State, which is aware
        // of all form values plus additional metadata.
        if (setFormState) {
          setFormState((prior: object) => {
            const newState = { ...prior, [target.name]: target.value };
            storeUpdateCallback(newState);
            return newState;
          });
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
        <OriginalInput {...passProps} attributes={attributes} />
      </>
    );
  }
  return WrappedInput;
};

export default InputBehaviors;
