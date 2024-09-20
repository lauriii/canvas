import { useState, useContext, useEffect, useCallback } from 'react';
import type * as React from 'react';
import { FormDispatchContext } from './Form';
import { selectSelectedComponent } from '@/features/ui/uiSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectLayout,
  selectModel,
  updateNodeModelForce,
} from '@/features/layout/layoutModelSlice';
import { debounce } from 'lodash';
import { parseValue } from '@/utils/function-utils';
import { useGetComponentsQuery } from '@/services/components';
import { findNodeByUuid } from '@/features/layout/layoutUtils';
import type { ComponentModel } from '@/features/layout/layoutModelSlice';
import type { FieldDataItem } from '@/types/Component';

export interface PropsValues {
  [key: string]: any;
}
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
    const defaultValue = options
      ? options.filter(
          (option: React.ComponentProps<any>) => option.selected,
        )?.[0]?.value
      : attributes.value || value || null;

    const [inputValue, setInputValue] = useState(defaultValue || '');
    const setFormState = useContext(FormDispatchContext);
    const { data: components } = useGetComponentsQuery();
    const layout = useAppSelector(selectLayout);
    const node = findNodeByUuid(layout, selectedComponent);
    const selectedComponentType = node ? (node.type as string) : 'noop';

    const formStateToStore = (newFormState: PropsValues) => {
      // Keep track
      const propsWithObjectValues: PropsValues = {};
      const propsWithSourceStorageSettings: PropsValues = {};

      // OpenAPI already ensures this exists, but the condition check is here
      // to soothe Typescript.
      if (components?.[selectedComponentType]?.['field_data']) {
        Object.entries(components[selectedComponentType]['field_data']).forEach(
          ([field_name, field]: [string, FieldDataItem]) => {
            if (field.jsonSchema?.properties) {
              propsWithObjectValues[field_name] = field.jsonSchema.properties;
            }
            if (field?.sourceTypeSettings?.storage) {
              propsWithSourceStorageSettings[field_name] =
                field.sourceTypeSettings.storage;
            }
          },
        );
      }

      // Keep track of fields that are part of a group of fields that result
      // in a single prop value being stored, such as individual date and time
      // fields being stored as a single datetime prop.
      const multipleInputsSingleValue: PropsValues = [];

      // Keep track of all props that have been checked, so we can identify
      // props that have multiple single-value fields associated with them.
      const propsInThisForm: string[] = [];
      Object.keys(newFormState).forEach((itemKey) => {
        if (itemKey.includes(`xb_component_props[${selectedComponent}][`)) {
          const fieldName = itemKey.split('][')[1];
          if (propsInThisForm.includes(fieldName)) {
            // If we hit a prop that is already in `propsInThisForm`, add it
            // to the array keeping track of props that have multiple single
            // value form elements associated with it.
            multipleInputsSingleValue.push(fieldName);
          } else {
            // Add this to the list of props we know the form can edit.
            propsInThisForm.push(fieldName);
          }
        }
      });
      // Get only the keys that correspond to SDC props.
      const keys = Object.keys(newFormState).filter((key) =>
        key.includes('xb_component_props['),
      );

      // Iterate through every item in form state that corresponds to
      // a component prop to create propsValues, which will ultimately be
      // used to update this component's model.
      const propsValues: PropsValues = keys.reduce(
        (newObject: PropsValues, key) => {
          // Extract the corresponding prop id from the form element name.
          const propId = key
            .replace(`xb_component_props[${selectedComponent}][`, '')
            .replace(/\].*$/, '');

          if (propsWithObjectValues[propId]) {
            // If this condition is met, it means the prop value is stored as
            // an object. `propsWithObjectValues[propId]` will have the schema
            // that can be referenced to determine how to shape the form data
            // into the object expected by the back end.
            console.warn(
              `The field ${propId} does not yet support updating the preview on change. It will soon.`,
            );
          } else if (multipleInputsSingleValue.includes(propId) && key.length) {
            // If this condition is met it means the field is part of a group
            // of fields that are collectively associated with a single prop value
            // (such as separate date and time fields -> single datetime value).

            // Get the sub-field name such as 'date' or 'time' in a datetime
            // widget.
            const subFieldParts: string[] = key.length ? key.split('][') : [''];
            const lastItem = subFieldParts.at(-1);
            const subField = lastItem ? lastItem.replace(']', '') : '';
            if (!newObject[propId]) {
              newObject[propId] = {};
            }
            if (subField.length) {
              newObject[propId][subField] = newFormState[key];
            } else {
              console.warn(
                `Attempt to update ${propId} with value from ${key}, but could not parse sub field.`,
              );
            }
          } else {
            // If this condition is met, it's a single value responding to a single prop.
            newObject[propId] = newFormState[key];
          }

          return newObject;
        },
        {},
      );

      // If a prop has source storage settings, the value may require additional
      // modification to meet its schema requirements.
      Object.entries(propsWithSourceStorageSettings).forEach(
        ([fieldName, storageSettings]) => {
          if (storageSettings?.datetime_type === 'datetime') {
            const dateField: PropsValues = propsValues[fieldName];
            const isoTime = new Date(
              `${dateField.date} ${dateField.time}+0000`,
            ).toISOString();
            propsValues[fieldName] = `${isoTime}`;
          }
        },
      );

      Object.entries(propsValues).forEach(([fieldName, value]) => {
        const fieldData: FieldDataItem | undefined =
          components?.[selectedComponentType]?.['field_data']?.[fieldName];

        // @todo below is special-casing for enum fields but we will need to do
        // this for many more use cases, so this should probably be moved to its
        // own utility once we have more use cases.
        if (fieldData?.jsonSchema?.enum) {
          if (!fieldData.jsonSchema.enum.includes(value)) {
            delete propsValues[fieldName as keyof PropsValues];
            delete selectedModel[fieldName as keyof ComponentModel];
          }
        }
      });

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
      } else if (attributes.name && setFormState) {
        // Note that checkbox is cast to bool to match the server prop
        // type requirements.
        setFormState((prior: object) => ({
          ...prior,
          [attributes.name]:
            attributes.type === 'checkbox' ? !!inputValue : inputValue,
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
        const target = e.target as HTMLInputElement | HTMLSelectElement;
        // If parsing results in a number that is not NaN, return the number, otherwise return the original string
        const value = parseValue(target.value, target as HTMLInputElement);

        // Update the value of the input - which belongs to just this instance
        // of inputBehaviors.
        setInputValue(value);

        // Check if the input is valid before continuing.
        if (target instanceof HTMLInputElement && !target.reportValidity()) {
          return;
        }

        // Then, update the Context-stored Form State, which is aware of all
        // form values plus additional metadata.
        if (setFormState) {
          setFormState((prior: object) => {
            const newState = { ...prior, [target.name]: value };
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
        <OriginalInput
          {...passProps}
          attributes={attributes}
          options={options}
        />
      </>
    );
  }
  return WrappedInput;
};

export default InputBehaviors;
