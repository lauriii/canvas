import Select from '@/components/form/components/Select';

import { castToSchemaType } from '../codecUtils';

import type {
  ClientWidgetContext,
  ClientWidgetDefinition,
  ClientWidgetProps,
} from '../types';

const NONE_VALUE = '_none';

/**
 * Reads the enum member schema for a prop: at the schema root for scalar
 * enums, inside `items` for multi-value (array) enums.
 */
const getEnumSchema = (
  context: ClientWidgetContext,
): { enum?: unknown[]; 'meta:enum'?: Record<string, string> } | undefined => {
  const schema = context.jsonSchema;
  const enumSchema =
    schema.type === 'array'
      ? (schema.items as { enum?: unknown[] } | undefined)
      : schema;
  return enumSchema && Array.isArray(enumSchema.enum)
    ? (enumSchema as ReturnType<typeof getEnumSchema>)
    : undefined;
};

const isMultiple = (context: ClientWidgetContext): boolean =>
  context.jsonSchema.type === 'array' || context.cardinality !== 1;

/**
 * Native counterpart of `options_select`: options come from the prop's JSON
 * schema `enum` (labels from `meta:enum`) in cached metadata, with no server
 * request and no server-side allowed-values recomputation.
 */
const OptionsSelectWidget = (props: ClientWidgetProps) => {
  const { value, onChange, disabled, required, inputId, inputName } = props;
  const enumSchema = getEnumSchema(props);
  const multiple = isMultiple(props);
  const labels = enumSchema?.['meta:enum'] ?? {};
  const options = (enumSchema?.enum ?? []).map((enumValue) => ({
    value: String(enumValue),
    label: labels[String(enumValue)] ?? String(enumValue),
    selected: false,
    type: 'option',
  }));
  // Optional single-value selects get core's `- None -` empty option so the
  // value can be cleared, matching the Drupal widget.
  const allOptions =
    !multiple && !required
      ? [
          {
            value: NONE_VALUE,
            label: '- None -',
            selected: false,
            type: 'option',
          },
          ...options,
        ]
      : options;

  const selectValue = multiple
    ? ((value as unknown[] | undefined) ?? []).map(String)
    : value === undefined || value === null || value === ''
      ? NONE_VALUE
      : String(value);

  return (
    <Select
      attributes={{
        id: inputId,
        name: multiple ? `${inputName}[]` : inputName,
        value: selectValue,
        multiple: multiple || undefined,
        required,
        disabled,
        onChange: (e: React.ChangeEvent<HTMLSelectElement>) => {
          if (multiple) {
            onChange(Array.from(e.target.selectedOptions).map((o) => o.value));
          } else {
            onChange(e.target.value);
          }
        },
      }}
      options={allOptions}
    />
  );
};

export const optionsSelectWidget: ClientWidgetDefinition = {
  component: OptionsSelectWidget,
  // A prop configured with options_select whose schema carries no enum falls
  // back to the escape hatch (the server computes its allowed values).
  isEligible: (context) => getEnumSchema(context) !== undefined,
  codec: {
    toModel(widgetValue, context) {
      const enumMemberType =
        context.jsonSchema.type === 'array'
          ? ((context.jsonSchema.items as { type?: string } | undefined)
              ?.type ?? 'string')
          : context.jsonSchema.type;
      if (isMultiple(context)) {
        const values = ((widgetValue as unknown[] | undefined) ?? [])
          .filter((v) => v !== NONE_VALUE && v !== '' && v !== null)
          .map((v) => castToSchemaType(v, context, enumMemberType));
        return values.length === 0 ? null : { resolved: values };
      }
      if (
        widgetValue === NONE_VALUE ||
        widgetValue === '' ||
        widgetValue === null ||
        widgetValue === undefined
      ) {
        return null;
      }
      return {
        resolved: castToSchemaType(widgetValue, context, enumMemberType),
      };
    },
    fromModel(sourceValue, resolvedValue, context) {
      const value = resolvedValue ?? sourceValue;
      if (isMultiple(context)) {
        return value === undefined || value === null
          ? []
          : [value].flat().map(String);
      }
      return value === undefined || value === null ? NONE_VALUE : String(value);
    },
  },
  handlesMultipleValues: true,
};
