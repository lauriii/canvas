import addFormats from 'ajv-formats';

import { isPropSourceComponent } from '@/types/Component';
import { createAjv } from '@/utils/ajv';
import { parseValue } from '@/utils/function-utils';
import transforms from '@/utils/transforms';

import {
  getPropsValues,
  propInputData,
  toDateTime,
  toPropName,
} from './componentFormData';

import type * as ReactType from 'react';
import type { PropsValues } from '@drupal-canvas/types';
import type { SchemaObject, ValidateFunction } from 'ajv';
import type { FieldDataItem } from '@/types/Component';
import type { InputUIData } from '@/types/Form';

const ajv = createAjv();

/**
 * Formats an Ajv errors array into a human-readable string.
 */
export const errorsText = (errors?: any) => ajv.errorsText(errors || null);

/**
 * Tuple containing validation result and validator function.
 * - [0] {boolean}: If true, then the validation passed
 * - [1] {ValidationFunction|null} - for returns where [0] is potentially
 *       false, the validation function is also passed, which can access
 *       information about the failure.
 *       @see node_modules/ajv/lib/types::ValidateFunction
 */
export type JsonSchemaValidationResult = [boolean, ValidateFunction | null];

/**
 * Validates data against a JSON Schema.
 *
 * @param {any} data
 *   The data to check against the schema.
 * @param {SchemaObject} schema
 *   The schema to validate against.
 * @return {JsonSchemaValidationResult}
 */
export function jsonSchemaValidate(
  data: any,
  schema: SchemaObject,
): JsonSchemaValidationResult {
  if (schema.format && !ajv.formats[schema.format]) {
    addFormats(ajv, [schema.format]);
    if (!ajv.formats[schema.format]) {
      console.warn(
        `A field was not validated because the following schema format is not available: ${schema.format} `,
      );
      return [true, null];
    }
  }

  // Properties prefixed with `x-` and `meta:enum` are not part of the JSON
  // Schema spec and must be filtered before passing to Ajv (strict mode).
  // Apply this recursively so nested schemas (e.g. `items` for array props)
  // are also cleaned.
  const stripNonStandardKeys = (s: Record<string, any>): Record<string, any> =>
    Object.entries(s).reduce<Record<string, any>>((carry, [key, value]) => {
      if (!key.match(/^x-/) && key !== 'meta:enum') {
        carry[key] =
          typeof value === 'object' && value !== null && !Array.isArray(value)
            ? stripNonStandardKeys(value)
            : value;
      }
      return carry;
    }, {});

  const filteredSchema = stripNonStandardKeys(schema);

  const validate = ajv.compile(filteredSchema);
  const valid = validate(data);
  return [valid, validate];
}

/**
 * Get an object of JSON schemas keyed by prop name for the currently selected
 * component.
 *
 * @param {InputUIData} inputAndUiData
 *   The specific properties required by this function:
 *   - components: the list of all available components
 *   - selectedComponentType: the `type` of the currently selected component
 */
export function getPropSchemas(inputAndUiData: InputUIData) {
  const { components, selectedComponentType } = inputAndUiData;
  const propSchemas: PropsValues = {};
  const component = components?.[selectedComponentType];
  if (isPropSourceComponent(component)) {
    Object.entries(component.propSources).forEach(
      ([propName, fieldData]: [string, FieldDataItem]) => {
        propSchemas[propName] = fieldData.jsonSchema;
      },
    );
  }
  return propSchemas;
}

/**
 * Determines if JSON Schema validation should be skipped for a prop.
 *
 * Ideally, this function can be removed at some point. It's here because the
 * schema validation currently only works for props managed by one form element.
 *
 * @param {string} name - The name attribute of the form element.
 * @param target - The HTMLInputElement being validated.
 * @param {InputUIData} inputAndUiData
 * @param newValue - The new value to potentially validate.
 * @return {boolean} true if JSON Validation should be skipped.
 */
export const shouldSkipPropValidation = (
  name: string,
  target: HTMLInputElement,
  inputAndUiData: InputUIData,
  newValue?: string | number | boolean | null,
): boolean => {
  if (!(target.form instanceof HTMLFormElement)) {
    return true;
  }

  // If the element is specifically flagged to skip validation, such as a weight
  // select that shouldn't be expected to match the item schema.
  if (target.dataset.canvasNoValidate) {
    return true;
  }

  // Reproduce core behavior of skipping validation for _none on selected
  // options where the select is not required.
  if (
    ['SELECT', 'OPTION'].includes(target.tagName) &&
    newValue === '_none' &&
    !target.required
  ) {
    return true;
  }

  // An empty string on an optional field can skip validation. For example, an
  // empty + optional URI field should not check for URI validity.
  if (newValue === '' && !target.required) {
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
 * Coerces a form value to the type expected by the prop schema.
 *
 * Form values are strings; when the schema expects integer, number, or boolean,
 * this applies the same cast transform that getPropsValues uses so validation
 * and submit see the same typed value.
 *
 * @param {any} value - The raw value (e.g. from an input).
 * @param {SchemaObject | undefined} schema - The prop's JSON Schema.
 * @return {any} The value, possibly coerced to the schema type.
 */
export function coerceValueForSchema(
  value: any,
  schema: SchemaObject | undefined,
): any {
  if (!schema?.type) {
    return value;
  }
  const propType = schema.type as string;
  if (
    (propType !== 'integer' &&
      propType !== 'number' &&
      propType !== 'boolean') ||
    typeof value !== 'string' ||
    value === ''
  ) {
    return propType === 'string' && typeof value === 'number'
      ? `${value}`
      : value;
  }

  const coerced = transforms.cast(
    value,
    { to: propType as 'integer' | 'number' | 'boolean' },
    undefined as any,
  );
  if (
    coerced !== null &&
    (typeof coerced !== 'number' || !Number.isNaN(coerced))
  ) {
    return coerced;
  }
  return value;
}

// ─── Prop validation ──────────────────────────────────────────────────────────

/**
 * Validates a prop's data against its JSON Schema.
 *
 * @param {string} schemaName - The prop name to look up the schema for.
 * @param {any} data - The data to check against the schema.
 * @param inputAndUiData - Used to retrieve the prop schemas.
 * @return {JsonSchemaValidationResult}
 */
export function validateProp(
  schemaName: string,
  data: any,
  inputAndUiData: InputUIData,
): JsonSchemaValidationResult {
  const schemas = getPropSchemas(inputAndUiData);
  if (schemas[schemaName]) {
    return jsonSchemaValidate(data, schemas[schemaName]);
  }
  return [true, null];
}

/**
 * Extracts the new value from a change event, applying any per-prop transforms
 * if available and appropriate.
 *
 * Returns the raw parsed value when transforms are absent, not applicable to
 * this prop, or the prop spans multiple inputs.
 */
export const parseNewValue = (
  e: ReactType.ChangeEvent,
  inputAndUiData: any,
  propName: string,
  selectedComponent: string,
  fieldTransforms: any,
  multipleInputsSingleValue: PropsValues,
) => {
  const schemas = getPropSchemas(inputAndUiData);
  const target = e.target as HTMLInputElement | HTMLSelectElement;
  const fieldName = target.name;
  const isNestedArraySubfield =
    fieldName &&
    schemas?.[propName]?.type === 'array' &&
    fieldName.startsWith(
      `canvas_component_props[${selectedComponent}][${propName}][`,
    );

  // A <select multiple> element's .value is only the last-clicked option.
  // For array props rendered as multi-selects, collect every selected option.
  if (target instanceof HTMLSelectElement && target.multiple) {
    return Array.from(target.selectedOptions).map((opt) => opt.value);
  }
  const rawValue = parseValue(
    (target as HTMLInputElement | HTMLSelectElement).value,
    target as HTMLInputElement,
    schemas?.[propName],
  );
  if (
    // If there are no transforms, we cannot use them, just return the raw
    // value. Note that the 'undefined' check here is technically not required
    // because at this point the form has loaded and the value will be
    // defined, it is required to satisfy type-checks.
    fieldTransforms === undefined ||
    Object.entries(fieldTransforms).length === 0 ||
    // Or if there are no transforms for this prop, don't bother with the
    // overhead of transforms.
    !(propName in fieldTransforms) ||
    // Or if the prop relies on multiple input fields.
    multipleInputsSingleValue.includes(propName) ||
    // Nested updates for array props (for example multivalue `_weight` and
    // `value` subfields) should bypass per-field transforms. Transforms will
    // be applied at the entire-prop level.
    isNestedArraySubfield
  ) {
    return rawValue;
  }
  const { propsValues: values } = getPropsValues(
    { [fieldName]: rawValue },
    inputAndUiData,
    fieldTransforms,
  );
  return propName in values ? values[propName] : rawValue;
};

/**
 * Validates a new value extracted from a change event against the prop's JSON
 * Schema.  Returns `{ valid: true }` immediately when validation should be
 * skipped (e.g. optional empty fields, multi-input props).
 */
export const validateNewValue = (
  e: ReactType.ChangeEvent,
  newValue: any,
  fieldName: string,
  selectedComponent: string,
  inputAndUiData: any,
) => {
  const target = e.target as HTMLInputElement;
  if (!shouldSkipPropValidation(fieldName, target, inputAndUiData, newValue)) {
    const schemas = getPropSchemas(inputAndUiData);
    const schema = schemas?.[toPropName(fieldName, selectedComponent)];
    const schemaToCoerceAgainst =
      schema?.type === 'array' ? schema.items : schema;
    const isEnum = schemaToCoerceAgainst?.enum && Array.isArray(newValue);
    let valueToValidate = !isEnum
      ? coerceValueForSchema(newValue, schemaToCoerceAgainst)
      : newValue.map((val: any) =>
          coerceValueForSchema(val, schemaToCoerceAgainst),
        );

    if ([schema?.format, schema?.items?.format].includes('date-time')) {
      valueToValidate = toDateTime(valueToValidate);
    }

    if (schema?.type === 'array' && !Array.isArray(valueToValidate)) {
      valueToValidate = [valueToValidate];
    }
    const [valid, validate] = validateProp(
      toPropName(fieldName, selectedComponent),
      valueToValidate,
      inputAndUiData,
    );
    return {
      valid,
      errors: validate?.errors || null,
    };
  }
  return { valid: true, errors: null };
};

/**
 * Adjusts the options list for select fields that contain a `_none` option.
 *
 * - For multi-selects (array props): removes `_none` entirely, since an empty
 *   array is the correct representation of "nothing selected".
 * - For single-selects with no current value: pre-selects `_none` so the form
 *   reflects the empty state accurately.
 *
 * Returns an empty object (no override) when no adjustment is needed.
 */
export const resolveOptionsOverrides = (
  props: Record<string, any>,
  inputAndUiData: any,
  selectedComponent: string,
  propName: string,
): { options?: object[] } => {
  if (!props.options || props.attributes.required) return {};

  if (!props.options.some((option: PropsValues) => option.value === '_none')) {
    return {};
  }

  if ('multiple' in props.attributes) {
    // For multi-select (array props), _none has no meaning: an empty
    // selection is represented as an empty array. Remove it entirely.
    return {
      options: props.options.filter(
        (option: PropsValues) => option.value !== '_none',
      ),
    };
  }

  if (!inputAndUiData?.model?.[selectedComponent]?.resolved?.[propName]) {
    return {
      options: props.options.map((option: PropsValues) => ({
        ...option,
        selected: option.value === '_none',
      })),
    };
  }

  return {};
};

interface JsonSchemaValidatorContext {
  fieldName: string;
  selectedComponent: string;
  inputAndUiData: InputUIData;
  required: boolean;
}

/**
 * Creates a react-hook-form validation function for JSON Schema validation.
 *
 * Designed to be passed directly to a Controller's `rules.validate` map so
 * the Controller render prop stays concise.
 *
 * @example
 * rules={{ validate: { jsonSchemaValidation: createJsonSchemaValidator(ctx) } }}
 */
export function createJsonSchemaValidator(ctx: JsonSchemaValidatorContext) {
  return (value: any): true | string => {
    const mockTarget = document.createElement('input');
    mockTarget.value = value || '';
    mockTarget.required = ctx.required;

    if (
      shouldSkipPropValidation(
        ctx.fieldName,
        mockTarget,
        ctx.inputAndUiData,
        value,
      )
    ) {
      return true;
    }

    const [valid, validate] = validateProp(
      toPropName(ctx.fieldName, ctx.selectedComponent),
      value,
      ctx.inputAndUiData,
    );

    return valid ? true : errorsText(validate?.errors || null);
  };
}
