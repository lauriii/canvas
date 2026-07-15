import type { ClientWidgetContext, WidgetCodec } from './types';

/**
 * Casts a widget's string value to the type the prop's JSON schema expects,
 * mirroring the `cast` transform semantics of the server-widget path.
 */
export function castToSchemaType(
  value: unknown,
  context: ClientWidgetContext,
  schemaType?: string,
): unknown {
  const type = schemaType ?? context.jsonSchema.type;
  if (value === null || value === undefined || typeof value !== 'string') {
    return value;
  }
  if (type === 'number') {
    return Number(value);
  }
  if (type === 'integer') {
    return parseInt(value, 10);
  }
  if (type === 'boolean') {
    return value === 'false' ? false : Boolean(value);
  }
  return value;
}

const isEmptyScalar = (value: unknown): boolean =>
  value === null || value === undefined || value === '';

/**
 * The codec for scalar widgets whose widget value IS the resolved model
 * value (the `mainProperty` + `cast` transform semantics): empty values
 * remove the prop from the model; non-empty values are cast to the schema
 * type.
 */
export const scalarCodec: WidgetCodec = {
  toModel(widgetValue, context) {
    if (isEmptyScalar(widgetValue)) {
      return null;
    }
    const resolved = castToSchemaType(widgetValue, context);
    if (typeof resolved === 'number' && Number.isNaN(resolved)) {
      return null;
    }
    return { resolved };
  },
  fromModel(sourceValue, resolvedValue) {
    const value = resolvedValue ?? sourceValue;
    return value === undefined || value === null ? '' : value;
  },
};
