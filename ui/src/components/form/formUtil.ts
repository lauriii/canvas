import type { FieldDataItem } from '@/types/Component';
import type { InputUIData, PropsValues } from '@/types/Form';
import type { ComponentModel } from '@/features/layout/layoutModelSlice';
import Ajv from 'ajv';
import type { ValidateFunction } from 'ajv';
import type * as React from 'react';
import addFormats from 'ajv-formats';
// @ts-ignore
import addDraft2019 from 'ajv-formats-draft2019';
const ajv = new Ajv();
addDraft2019(ajv);

/**
 * Get an object of array schemas keyed by prop name.
 *
 * @param {InputUIData} inputAndUiData
 *   An object usually generated on render in inputBehaviors.tsx.
 *   The specific properties required by this function:
 *   - components {ComponentsList|undefined}: the list of all available components,
 *     managed by `services/componentApi`
 *   - selectedComponentType {string}: the `type` property of the currently
 *     selected component.
 */
export function getPropSchemas(inputAndUiData: InputUIData) {
  const { components, selectedComponentType } = inputAndUiData;
  const propSchemas: PropsValues = {};
  if (components?.[selectedComponentType]?.['field_data']) {
    Object.entries(components[selectedComponentType]['field_data']).forEach(
      ([propName, fieldData]: [string, FieldDataItem]) => {
        propSchemas[propName] = fieldData.jsonSchema;
      },
    );
  }
  return propSchemas;
}

/**
 * Validates data against a JSON Schema
 * @param {string} schemaName
 *   The schema name.
 * @param {any} data
 *   The data to check against the schema.
 * @param inputAndUiData
 *   An object usually generated on render in inputBehaviors.tsx with information
 *   about the form and props. This is needed for passing to getPropSchemas().
 *
 * @return {Array} validation data.
 *   - [0] {boolean}: If true, then the validation passed
 *   - [1] {ValidationFunction|null} - for returns where [0] is potentially
 *         false, the validation function is also passed, which can access
 *         information about the failure.
 *         @see node_modules/ajv/lib/types::ValidateFunction
 */
export function jsonValidate(
  schemaName: string,
  data: any,
  inputAndUiData: InputUIData,
): [boolean, ValidateFunction | null] {
  const schemas = getPropSchemas(inputAndUiData);
  if (schemas[schemaName]) {
    const schema = schemas[schemaName];
    if (schema.format && !ajv.formats[schema.format]) {
      addFormats(ajv, [schema.format]);
      if (!ajv.formats[schema.format]) {
        console.warn(
          `A field was not validated because the following schema format is not available: ${schema.format} `,
        );
        return [true, null];
      }
    }
    const validate = ajv.compile(schema);
    const valid = validate(data);
    return [valid, validate];
  }
  return [true, null];
}

/**
 * Takes a prop form element's `name` attribute and returns the prop name.
 *
 * @param {string} inputName
 *   The name attribute of the form element.
 * @param {string} selectedComponent
 *   The ID of the currently selected component.
 */
export function toPropName(inputName: string, selectedComponent: string) {
  return inputName
    .replace(`xb_component_props[${selectedComponent}][`, '')
    .replace(/\].*$/, '');
}

/**
 * Analyzes a form state and returns an object that organizes the form
 * information in multiple ways to satisfy different use cases.
 *
 * @param {object} formState
 *   An object with any number of {formElementName: formElementValue}.
 * @param {InputUIData} inputAndUiData
 *   An object usually generated on render in inputBehaviors.tsx.
 *   The specific properties required by this function:
 *   - components {ComponentsList|undefined}: the list of all available components,
 *     managed by `services/componentApi`
 *   - selectedComponentType {string}: the `type` property of the currently
 *     selected component.
 *   - selectedComponent {string}: the id of the selected component within the model.
 *
 *  @return {object}
 *    - multipleInputsSingleValue {array}: an array of prop names where a single
 *      non-object prop value is managed by more than one form element.
 *    - propsInThisForm {array}: an array of the names of the props represented
 *      in formState.
 *    -  propsWithObjectValues {array}: an array of the names of the props with
 *       values stored as objects.
 *    -  propsWithSourceStorageSettings {array}: an array of the names of the
 *       props with source storage settings.
 */
export function propInputData(
  formState: PropsValues,
  inputAndUiData: InputUIData,
) {
  const { selectedComponent, components, selectedComponentType } =
    inputAndUiData;
  // Keep track of fields that are part of a group of fields that result
  // in a single prop value being stored, such as individual date and time
  // fields being stored as a single datetime prop.
  const multipleInputsSingleValue: PropsValues = [];

  // Keep track of all props that have been checked, so we can identify
  // props that have multiple single-value fields associated with them.
  const propsInThisForm: string[] = [];
  Object.keys(formState).forEach((itemKey) => {
    if (itemKey.includes(`xb_component_props[${selectedComponent}][`)) {
      const propName = itemKey.split('][')[1];
      if (propsInThisForm.includes(propName)) {
        // If we hit a prop that is already in `propsInThisForm`, add it
        // to the array keeping track of props that have multiple single
        // value form elements associated with it.
        multipleInputsSingleValue.push(propName);
      } else {
        // Add this to the list of props we know the form can edit.
        propsInThisForm.push(propName);
      }
    }
  });

  const propsWithObjectValues: PropsValues = {};
  const propsWithSourceStorageSettings: PropsValues = {};
  // OpenAPI already ensures this exists, but the condition check is here
  // to soothe Typescript.
  if (components?.[selectedComponentType]?.['field_data']) {
    Object.entries(components[selectedComponentType]['field_data']).forEach(
      // @ts-ignore
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
  return {
    multipleInputsSingleValue,
    propsInThisForm,
    propsWithObjectValues,
    propsWithSourceStorageSettings,
  };
}

/**
 * Determines what a form element default value should be.
 *
 * @param {PropsValues | undefined} options
 *   When present, an object of {id : value} representing an element's options.
 * @param {PropsValues | undefined} attributes
 *   The attributes object passed to most form elements
 * @param value {any}
 *   The `value` prop as passed to the form element component.
 *
 * @return {any}
 *   The default value for the input.
 */
export function getDefaultValue(
  options: PropsValues | undefined,
  attributes: PropsValues | undefined,
  value: any,
) {
  // If options are present:
  // - If an option is defined as selected, use that value
  // Else if `attributes.value` is truthy, use that value.
  // Else if `value` is truthy, use that value.
  // Otherwise, return null.
  return options
    ? options.find((option: React.ComponentProps<any>) => option.selected)
        ?.value
    : attributes?.value || value || null;
}

/**
 * Takes a formState and provides an object keyed by prop name with the
 * corresponding prop values.
 *
 * @param {object} formState
 *   An object with any number of {formElementName: formElementValue}.
 * @param {InputUIData} inputAndUiData
 *   An object usually generated on render in inputBehaviors.tsx.
 *   The specific properties required by this function:
 *   - components {ComponentsList|undefined}: the list of all available components,
 *     managed by `services/componentApi`
 *   - selectedComponentType {string}: the `type` property of the currently
 *     selected component.
 *   - selectedComponent {string}: the id of the selected component within the model.
 *   - model {ComponentModels|undefined}: the model of the selected component.
 */
export function getPropsValues(
  formState: PropsValues,
  inputAndUiData: InputUIData,
) {
  const { selectedComponent, model, components, selectedComponentType } =
    inputAndUiData;
  const selectedModel = model ? { ...model[selectedComponent] } : {};
  const {
    propsWithSourceStorageSettings,
    multipleInputsSingleValue,
    propsWithObjectValues,
  } = propInputData(formState, inputAndUiData);
  const keys = Object.keys(formState).filter((key) =>
    key.includes('xb_component_props['),
  );
  // Iterate through every item in form state that corresponds to
  // a component prop to create propsValues, which will ultimately be
  // used to update this component's model.
  const propsValues: PropsValues = keys.reduce(
    (newObject: PropsValues, key) => {
      // Extract the corresponding prop id from the form element name.
      const propId = toPropName(key, selectedComponent);
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
          newObject[propId][subField] = formState[key];
        } else {
          console.warn(
            `Attempt to update ${propId} with value from ${key}, but could not parse sub field.`,
          );
        }
      } else {
        // If this condition is met, it's a single value responding to a single prop.
        newObject[propId] = formState[key];
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
        try {
          const isoTime = new Date(
            `${dateField.date} ${dateField.time}+0000`,
          ).toISOString();
          propsValues[fieldName] = `${isoTime}`;
        } catch (err) {
          delete propsValues[fieldName as keyof PropsValues];
          delete selectedModel[fieldName as keyof ComponentModel];
        }
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
    // If the value is an empty string, don't store it at all.
    if (value === '') {
      delete propsValues[fieldName as keyof PropsValues];
      delete selectedModel[fieldName as keyof ComponentModel];
    }
  });

  return { propsValues, selectedModel };
}
