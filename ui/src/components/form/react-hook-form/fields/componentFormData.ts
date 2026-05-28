import qs from 'qs';

import { isPropSourceComponent } from '@/types/Component';
import { getDrupal } from '@/utils/drupal-globals';
import transforms from '@/utils/transforms';

import type { PropsValues } from '@drupal-canvas/types';
import type { ParsedQs } from 'qs';
import type {
  ComponentModel,
  EvaluatedComponentModel,
} from '@/features/layout/layoutModelSlice';
import type { FieldDataItem, PropSourceComponent } from '@/types/Component';
import type { InputUIData } from '@/types/Form';
import type { TransformConfig, Transforms } from '@/utils/transforms';

export const DEBOUNCE_TIMEOUT = 400;

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
    .replace(`canvas_component_props[${selectedComponent}][`, '')
    .replace(/\].*$/, '');
}

export const isDateOnly = (val: string): boolean =>
  /^\d{4}-\d{2}-\d{2}$/.test(val);

export const isTimeOnly = (val: string): boolean =>
  /^\d{2}:\d{2}(:\d{2})?$/.test(val);

/**
 * Normalizes a bare date or time string into a full ISO-8601 datetime so it
 * can be compared against a `date-time` JSON Schema format.
 */
export const toDateTime = (val: string): string => {
  if (isDateOnly(val)) return `${val}T00:00:00Z`;
  if (isTimeOnly(val)) return `1970-01-01T${val}Z`;
  return val;
};

type QueryValue = undefined | string | ParsedQs | (string | ParsedQs)[];
const isParsedQ = (parsed: QueryValue): parsed is ParsedQs =>
  typeof parsed === 'object';

/**
 * Converts a flat Drupal-style form state object (keyed by form element name)
 * into a nested object keyed by prop name.
 *
 * Uses `qs` + `URLSearchParams` internally to decode the bracket-notation names
 * that Drupal form elements use.  Array props require special handling because
 * URLSearchParams stringifies everything — see inline comments.
 */
export const formStateToObject = (
  formState: PropsValues,
  componentId: string,
): PropsValues => {
  const params = new URLSearchParams();
  const arrayPropNames: string[] = [];
  const prefix = `canvas_component_props[${componentId}][`;
  Object.entries(formState).forEach(([key, value]) => {
    // Drupal's <select multiple> appends `[]` to the element name.
    // Strip it so the single-bracket check works for both forms:
    //   `...[colors]`   -> direct prop key (from JS dispatch)
    //   `...[colors][]` -> direct prop key (from Drupal multi-select)
    //   `...[video][0][fids]` -> nested widget key (not a direct prop)
    const normalizedKey = key.endsWith('[]') ? key.slice(0, -2) : key;
    const isDirectArrayProp =
      Array.isArray(value) &&
      normalizedKey.startsWith(prefix) &&
      normalizedKey.indexOf(']', prefix.length) === normalizedKey.length - 1;
    if (isDirectArrayProp) {
      arrayPropNames.push(toPropName(normalizedKey, componentId));
      if ((value as any[]).length) {
        (value as any[]).forEach((item) => params.append(key, item));
      } else {
        // Represent an empty array with an empty string to convey an
        // empty value in the query string.
        params.append(key, '');
      }
    } else {
      params.append(key, value as any);
    }
  });
  const parsed = qs.parse(params.toString());
  if (
    !isParsedQ(parsed.canvas_component_props) ||
    !parsed.canvas_component_props[componentId]
  ) {
    return {};
  }
  const result = parsed.canvas_component_props[componentId] as PropsValues;
  arrayPropNames.forEach((propName) => {
    if (!(propName in result)) {
      result[propName] = [];
    } else if (
      result[propName] === '' ||
      // When the key has a `[]` suffix, qs.parse wraps the sentinel empty
      // string into a single-element array [''] — treat that as empty too.
      (Array.isArray(result[propName]) &&
        (result[propName] as any[]).length === 1 &&
        (result[propName] as any[])[0] === '')
    ) {
      // An empty string (or ['']) is our sentinel for an empty array.
      result[propName] = [];
    } else if (!Array.isArray(result[propName])) {
      result[propName] = [result[propName]];
    }
  });
  return result;
};

/**
 * Analyzes a form state and returns an object that organizes the form
 * information in multiple ways to satisfy different use cases.
 *
 * @param {object} formState
 *   An object with any number of {formElementName: formElementValue}.
 * @param {InputUIData} inputAndUiData
 *   An object usually generated on render by components wrapped with withRHF.
 *   The specific properties required by this function:
 *   - components {ComponentsList|undefined}: the list of all available components,
 *     managed by `services/componentAndLayoutApi`
 *   - selectedComponentType {string}: the `type` property of the currently
 *     selected component.
 *   - selectedComponent {string}: the id of the selected component within the model.
 *
 *  @return {object}
 *    - multipleInputsSingleValue {array}: an array of prop names where a single
 *      non-object prop value is managed by more than one form element.
 *    - propsInThisForm {array}: an array of the names of the props represented
 *      in formState.
 *    - propsWithObjectValues {array}: an array of the names of the props with
 *      values stored as objects.
 *    - propsWithSourceStorageSettings {array}: an array of the names of the
 *      props with source storage settings.
 */
export function propInputData(
  formState: PropsValues,
  inputAndUiData: InputUIData,
) {
  const { selectedComponent, components, selectedComponentType } =
    inputAndUiData;

  const component = components?.[selectedComponentType];

  // Keep track of fields that are part of a group of fields that result
  // in a single prop value being stored, such as individual date and time
  // fields being stored as a single datetime prop.
  const multipleInputsSingleValue: PropsValues = [];

  // Keep track of all props that have been checked, so we can identify
  // props that have multiple single-value fields associated with them.
  const propsInThisForm: string[] = [];
  Object.keys(formState).forEach((itemKey) => {
    if (itemKey.includes(`canvas_component_props[${selectedComponent}][`)) {
      const propName = toPropName(itemKey, selectedComponent);
      // @ts-ignore
      const cardinality =
        isPropSourceComponent(component) &&
        component?.propSources?.[propName]?.sourceTypeSettings?.cardinality;
      if (
        propsInThisForm.includes(propName) &&
        (!cardinality || cardinality === 1)
      ) {
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

  if (isPropSourceComponent(component)) {
    Object.entries(component.propSources).forEach(
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
 * Takes a formState and provides an object keyed by prop name with the
 * corresponding prop values.
 *
 * @param {object} formState
 *   An object with any number of {formElementName: formElementValue}.
 * @param {InputUIData} inputAndUiData
 *   An object usually generated on render by components wrapped with withRHF.
 *   The specific properties required by this function:
 *   - components {ComponentsList|undefined}: the list of all available components,
 *     managed by `services/componentAndLayoutApi`
 *   - selectedComponentType {string}: the `type` property of the currently
 *     selected component.
 *   - selectedComponent {string}: the id of the selected component within the model.
 *   - model {ComponentModels|undefined}: the model of the selected component.
 * @param {TransformConfig} transformConfig - Transforms to use
 */
export function getPropsValues(
  formState: PropsValues,
  inputAndUiData: InputUIData,
  transformConfig: TransformConfig = {},
) {
  const { selectedComponent, model, components, selectedComponentType } =
    inputAndUiData;
  const selectedModel = model
    ? { ...model[selectedComponent] }
    : ({} as ComponentModel);
  const component = components?.[selectedComponentType];
  const fieldData = isPropSourceComponent(component)
    ? component.propSources
    : {};
  // Iterate through every item in form state that corresponds to
  // a component input to create propsValues, which will ultimately be
  // used to update this component's model.
  const Drupal = getDrupal() || {
    Drupal: { canvasTransforms: transforms },
  };
  const transformsList: Transforms = Drupal?.canvasTransforms || transforms;
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
            (selectedModel as EvaluatedComponentModel).source[key] as any,
          );
        },
        value,
      );
      if (transformed === null) {
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
    const propFieldData: FieldDataItem | undefined =
      (isPropSourceComponent(component)
        ? component.propSources[fieldName]
        : undefined) || undefined;

    // @todo below is special-casing for enum fields but we will need to do
    // this for many more use cases, so this should probably be moved to its
    // own utility once we have more use cases. Could we represent this with a
    // transform?
    if (propFieldData?.jsonSchema?.enum) {
      if (!propFieldData.jsonSchema.enum.includes(value)) {
        delete propsValues[fieldName as keyof PropsValues];
        const resolved = { ...selectedModel.resolved };
        delete resolved[fieldName as keyof ComponentModel['resolved']];
        selectedModel.resolved = resolved;
      }
    }

    // Slice the array to the configured `maxItems` available so it doesn't fail
    // to render.
    const maxItems = propFieldData?.jsonSchema?.maxItems;
    if (Array.isArray(value) && maxItems && value.length > maxItems) {
      propsValues[fieldName as keyof PropsValues] = value.slice(0, maxItems);
    }

    // If the value is empty on an optional field, but the field has format
    // requirements, we should not store it.
    // @todo: this means that if an optional field has format requirements, it's
    //   not truly optional as the empty value will not be stored.
    const emptyOptionalWithFormatRequirements =
      value === '' &&
      !propFieldData?.required &&
      propFieldData?.jsonSchema?.format;

    if (emptyOptionalWithFormatRequirements) {
      delete propsValues[fieldName as keyof PropsValues];
      const resolved = { ...selectedModel.resolved };
      delete resolved[fieldName as keyof ComponentModel['resolved']];
      selectedModel.resolved = resolved;
    }

    if (
      value === '' &&
      propFieldData?.jsonSchema?.type === 'object' &&
      propFieldData?.required &&
      component
    ) {
      // '' is an empty value, but we require a valid object here, so we
      // fall back to the default value.
      propsValues[fieldName as keyof PropsValues] = (
        component as PropSourceComponent
      ).propSources[fieldName as keyof PropsValues].default_values.resolved;
    }
  });

  return { propsValues, selectedModel };
}
