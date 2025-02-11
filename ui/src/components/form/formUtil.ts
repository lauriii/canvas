import type { FieldDataItem } from '@/types/Component';
import { componentHasFieldData } from '@/types/Component';
import type { InputUIData, PropsValues } from '@/types/Form';
import type { ComponentModel } from '@/features/layout/layoutModelSlice';
import Ajv from 'ajv';
import type { ValidateFunction } from 'ajv';
import type * as React from 'react';
import addFormats from 'ajv-formats';
import type { ParsedQs } from 'qs';
import type { Transforms } from '@/utils/transforms';
import transforms from '@/utils/transforms';
import qs from 'qs';
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
  const component = components?.[selectedComponentType];
  if (componentHasFieldData(component)) {
    Object.entries(component.field_data).forEach(
      ([propName, fieldData]: [string, FieldDataItem]) => {
        propSchemas[propName] = fieldData.jsonSchema;
      },
    );
  }
  return propSchemas;
}

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
export const shouldSkipJsonValidation = (
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
  const component = components?.[selectedComponentType];
  if (componentHasFieldData(component)) {
    Object.entries(component.field_data).forEach(
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

type QueryValue = undefined | string | ParsedQs | (string | ParsedQs)[];
export const isParsedQ = (parsed: QueryValue): parsed is ParsedQs => {
  return typeof parsed === 'object';
};

export const formStateToObject = (
  formState: PropsValues,
  componentId: string,
): PropsValues => {
  const params = new URLSearchParams();
  Object.entries(formState).forEach(([key, value]) => {
    params.append(key, value);
  });
  const parsed = qs.parse(params.toString());
  if (isParsedQ(parsed.xb_component_props)) {
    return parsed.xb_component_props[componentId] as PropsValues;
  }
  return {};
};

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
  const selectedModel = model
    ? { ...model[selectedComponent] }
    : ({} as ComponentModel);
  const component = components?.[selectedComponentType];
  const transformConfig = componentHasFieldData(component)
    ? component.transforms
    : {};
  const fieldData = componentHasFieldData(component)
    ? component.field_data
    : {};
  // Iterate through every item in form state that corresponds to
  // a component input to create propsValues, which will ultimately be
  // used to update this component's model.
  const { Drupal } = (window as any) || {
    Drupal: { xbTransforms: transforms },
  };
  const transformsList: Transforms = Drupal?.xbTransforms || transforms;
  const propsValues = Object.entries(
    formStateToObject(formState, selectedComponent),
  ).reduce((carry: PropsValues, [key, value]) => {
    if (key in transformConfig) {
      let fieldTransforms = transformConfig[key];
      // Internally to formStateToObject we make use of the `qs` npm package and
      // URLSearchParams to convert nested named form elements into a nested
      // structure. Because URLSearchParams converts all values to strings so
      // they can be represented in a URL, we need to take care to cast some
      // values back to their expected type. This is not dissimilar to how PHP
      // receives multipart form data in so far as everything is seen as a
      // string value.
      // @see formStateToObject
      const propType = fieldData[key]?.jsonSchema?.type ?? 'string';
      if (['boolean', 'number', 'integer'].includes(propType)) {
        // Push an additional 'cast' transform to the end of the transforms for
        // this prop.
        fieldTransforms = {
          ...fieldTransforms,
          cast: { to: propType },
        };
      }
      // Apply each transform in sequence.
      const transformed = Object.entries(fieldTransforms).reduce(
        (transformed: any, [transformer, config]) => {
          return transformsList[transformer as keyof Transforms](
            transformed,
            config as any,
            fieldData[key] as any,
          );
        },
        value,
      );
      if (transformed === null) {
        // Ignore null values.
        return carry;
      }
      return {
        ...carry,
        [key]: transformed,
      };
    }

    return { ...carry, [key]: value };
  }, {});

  Object.entries(propsValues).forEach(([fieldName, value]) => {
    const fieldData: FieldDataItem | undefined =
      (componentHasFieldData(component)
        ? component.field_data[fieldName]
        : undefined) || undefined;

    // @todo below is special-casing for enum fields but we will need to do
    // this for many more use cases, so this should probably be moved to its
    // own utility once we have more use cases. Could we represent this with a
    // transform?
    if (fieldData?.jsonSchema?.enum) {
      if (!fieldData.jsonSchema.enum.includes(value)) {
        delete propsValues[fieldName as keyof PropsValues];
        const resolved = { ...selectedModel.resolved };
        delete resolved[fieldName as keyof ComponentModel['resolved']];
        selectedModel.resolved = resolved;
      }
    }
    // If the value is an empty string, don't store it at all.
    if (value === '') {
      delete propsValues[fieldName as keyof PropsValues];
      const resolved = { ...selectedModel.resolved };
      delete resolved[fieldName as keyof ComponentModel['resolved']];
      selectedModel.resolved = resolved;
    }
  });

  return { propsValues, selectedModel };
}

// @todo Remove this in favor of https://www.drupal.org/i/3497759.
// This function was written for radios where the wrapper element doesn't have
// a name attribute, but we want to handle the input values at that level,
// because we want to maintain the value for the radio group.
// @see ui/src/components/form/components/drupal/DrupalRadio.tsx
// @see ui/src/components/form/components/Radio.tsx
// This is a naïve and fragile implementation, we really shouldn't be relying on
// the ID to determine the name attribute.
export function getNameAttributeBasedOnId(id: string) {
  // Remove the 'edit-' prefix
  const withoutPrefix = id.replace(/^edit-/, '');

  // Check if there's a field delta in the ID (e.g., comment-0-status)
  const match = withoutPrefix.match(/^(.+?)-(\d+)-(.+)$/);

  if (match) {
    // If we have a delta, format as field_name[delta][property]
    const [, fieldName, delta, property] = match;
    // Convert dashes to underscores in both in the field name and property.
    const cleanFieldName = fieldName.replace(/-/g, '_');
    const cleanProperty = property.replace(/-/g, '_');
    return `${cleanFieldName}[${delta}][${cleanProperty}]`;
  } else {
    // If no delta, default to field_name[0][value]. Big assumption, may not hold off in all cases.
    // Convert dashes to underscores in the field name.
    const fieldName = withoutPrefix.replace(/-/g, '_');
    return `${fieldName}[0][value]`;
  }
}
