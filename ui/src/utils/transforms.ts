import type { PropsValues } from '@drupal-canvas/types';
import type {
  PropSource,
  StaticPropSource,
} from '@/features/layout/layoutModelSlice';

export interface TransformOptions {
  [key: string]: any;
}

export type Transform = {
  [key in keyof Transforms]: TransformOptions;
};

export interface TransformConfig {
  [key: keyof PropsValues]: Partial<Transform>;
}

export const ENTITY_AUTOCOMPLETE_MATCH = /.+\s\(([^)]+)\)/;

type PropsValuesOrArrayOfPropsValues =
  | Array<PropsValues>
  | PropsValues
  | null
  | undefined;

type BaseTransformOptions = {
  multiple?: boolean;
};

/**
 * Maps empty strings to null and removes trailing null entries.
 *
 * Empty strings become null to preserve positional placeholders so the backend
 * can render filled rows at the correct positions. Trailing nulls are removed
 * because rows empty at the end have no positional significance.
 */
const trimTrailingNulls = <T>(
  values: Array<T | null | string>,
): Array<T | null> => {
  const normalized = values.map((v) =>
    v === '' ? null : v,
  ) as Array<T | null>;
  let end = normalized.length;
  while (end > 0 && normalized[end - 1] === null) {
    end--;
  }
  return normalized.slice(0, end);
};

const normalizeMultipleRecords = (
  value: PropsValuesOrArrayOfPropsValues,
): Array<PropsValues | null> => {
  if (value === null || value === undefined) {
    return [];
  }

  if (typeof value !== 'object') {
    return [value];
  }

  const values = Object.values(value);
  // Support both `weight` (media library) and `_weight` (other widgets).
  const hasWeightedRecords = values.some(
    (item) =>
      typeof item === 'object' &&
      item !== null &&
      ('_weight' in item || 'weight' in item),
  );

  if (hasWeightedRecords) {
    values.sort((a, b) => {
      const weightA =
        typeof a === 'object' && a !== null
          ? Number(a._weight ?? a.weight)
          : Number.NaN;
      const weightB =
        typeof b === 'object' && b !== null
          ? Number(b._weight ?? b.weight)
          : Number.NaN;

      // Keep helper values such as `add_more` after weighted records.
      const normalizedWeightA = Number.isNaN(weightA)
        ? Number.MAX_SAFE_INTEGER
        : weightA;
      const normalizedWeightB = Number.isNaN(weightB)
        ? Number.MAX_SAFE_INTEGER
        : weightB;
      return normalizedWeightA - normalizedWeightB;
    });
  }

  return values;
};

type Transformer<
  TransformerOptions extends BaseTransformOptions,
  TransformerReturn extends unknown = any,
  TransformerInput extends unknown = PropsValuesOrArrayOfPropsValues,
  FieldPropShape extends PropSource = StaticPropSource,
> = (
  value: TransformerInput,
  options: TransformerOptions,
  fieldPropShape: FieldPropShape,
) => TransformerReturn;

const mainProperty: Transformer<{
  name?: keyof PropsValues;
  multiple?: boolean;
}> = (value, options): any => {
  const { name = 'value', multiple = false } = options;

  // Handle null/undefined input for entire value
  if (value === null || value === undefined) {
    return multiple ? [null] : null;
  }

  let records: Array<PropsValues | null>;
  if (multiple) {
    records = normalizeMultipleRecords(value);
  } else {
    records = Array.isArray(value) ? value : [value];
  }
  const returnValue = records.map((record: PropsValues | null) =>
    record !== null && typeof record === 'object' && name in record
      ? record[name]
      : null,
  );
  if (multiple) {
    return returnValue.filter((v) => v !== null && v !== '');
  }
  return returnValue.shift();
};

const firstRecord: Transformer<BaseTransformOptions, null | PropsValues> = (
  value,
) => {
  if (value == null || (Array.isArray(value) && value.length === 0)) {
    return null;
  }
  return (Array.isArray(value) ? value[0] : value) as PropsValues;
};

export interface LinkPropShape extends StaticPropSource {
  sourceTypeSettings: {
    instance: {
      // @see DRUPAL_DISABLED
      // @see DRUPAL_OPTIONAL
      // @see DRUPAL_REQUIRED
      title: 0 | 1 | 2;
    };
  };
}

const resolveEntityUri = (uri: string): string => {
  const match = uri.match(ENTITY_AUTOCOMPLETE_MATCH);
  // LinkWidget with autocomplete support only supports matching on node
  // entities.
  // @todo Add support for other entity types once core does -
  // https://www.drupal.org/i/2423093
  return match !== null ? `entity:node/${match[1]}` : uri;
};

const hasStringUri = (
  value: unknown,
): value is PropsValues & { uri: string } => {
  if (typeof value !== 'object' || value === null || !('uri' in value)) {
    return false;
  }
  return typeof value.uri === 'string';
};

const link: Transformer<
  BaseTransformOptions,
  Array<null | string | PropsValues> | null | string | PropsValues,
  PropsValuesOrArrayOfPropsValues,
  LinkPropShape
> = (value, options, propSource) => {
  if (value == null) return options.multiple ? [] : null;

  const records: Array<unknown> = options.multiple
    ? normalizeMultipleRecords(value)
    : [value].flat();
  const returnValue = records.map((record: unknown) => {
    // `1` corresponds to `DRUPAL_OPTIONAL` and `2` to DRUPAL_REQUIRED on the
    // server side.
    // @see DRUPAL_DISABLED
    // @see DRUPAL_OPTIONAL
    // @see DRUPAL_REQUIRED
    if (![1, 2].includes(propSource?.sourceTypeSettings?.instance?.title)) {
      const uri = mainProperty(
        [record as PropsValues],
        { name: 'uri', multiple: false },
        propSource,
      );
      return uri != null ? resolveEntityUri(uri) : uri;
    }
    if (record === null || typeof record !== 'object') {
      return null;
    }
    return hasStringUri(record)
      ? { ...record, uri: resolveEntityUri(record.uri) }
      : (record as PropsValues);
  });
  if (options.multiple) {
    return trimTrailingNulls(returnValue);
  }
  return returnValue[0] ?? null;
};

const cast: Transformer<
  {
    to: 'number' | 'boolean' | 'integer';
    multiple?: boolean;
  },
  null | number | boolean | Array<null | number | boolean>,
  null | string | Array<null | string>
> = (value, options) => {
  const { to = 'number', multiple = false } = options;
  const records: Array<null | string> = Array.isArray(value) ? value : [value];
  const returnValue = records.map((value: null | string) => {
    if (value === null) {
      return null;
    }
    if (to === 'number') {
      return Number(value);
    }
    if (to === 'integer') {
      return parseInt(value);
    }
    if (value === 'false') {
      return false;
    }
    return Boolean(value);
  });
  if (multiple) {
    return returnValue;
  }
  const singleValue = returnValue.shift();
  if (singleValue === undefined) {
    return null;
  }
  return singleValue;
};

interface DateFieldPropSource extends StaticPropSource {
  sourceTypeSettings: {
    storage: {
      // @see \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem::DATETIME_TYPE_DATE
      // @see \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem::DATETIME_TYPE_DATETIME
      datetime_type: 'date' | 'datetime';
    };
  };
}

const dateTime: Transformer<
  {
    type: 'date' | 'datetime';
    multiple?: boolean;
  },
  null | string | Array<null | string>,
  PropsValuesOrArrayOfPropsValues,
  DateFieldPropSource
> = (value, options, propSource) => {
  if (propSource === null || propSource === undefined) {
    return null;
  }
  const type = propSource.sourceTypeSettings.storage.datetime_type;
  // @see \Drupal\Component\Datetime\DateTimePlus::setDefaultDateTime
  const records: Array<PropsValues | null> = [
    value,
  ].flat() as Array<PropsValues | null>;

  const returnValue = records.map((record: PropsValues | null) => {
    if (record === null) {
      return null;
    }
    let timeString = '12:00:00';
    if (!('date' in record)) {
      return null;
    }
    const dateString = record.date;
    if (type === 'date') {
      return dateString;
    }
    if ('time' in record) {
      timeString = record.time;
    }
    // @todo Update this in https://www.drupal.org/project/canvas/issues/3501281, which will allow removing the FE-special casing in \Drupal\canvas\PropExpressions\StructuredData\Evaluator::evaluate()
    return new Date(`${dateString} ${timeString}+0000`).toISOString();
  });
  if (options.multiple) {
    return trimTrailingNulls(returnValue);
  }
  const singleValue = returnValue.shift();
  if (singleValue === undefined) {
    return null;
  }
  return singleValue;
};

const dateRange: Transformer<
  BaseTransformOptions,
  null | PropsValues,
  PropsValuesOrArrayOfPropsValues,
  DateFieldPropSource
> = (value, _options, propSource) => {
  if (propSource === null || propSource === undefined) {
    return null;
  }
  if (value === null) {
    return null;
  }
  let first = value as PropsValues;
  if (Array.isArray(value)) {
    if (value.length === 0) {
      return null;
    }
    first = value[0] as PropsValues;
  }
  if (
    typeof first !== 'object' ||
    first === null ||
    !('value' in first) ||
    !('end_value' in first) ||
    typeof first.value !== 'object' ||
    first.value === null ||
    typeof first.end_value !== 'object' ||
    first.end_value === null
  ) {
    return null;
  }

  const dateTimeOptions = {
    type: propSource.sourceTypeSettings.storage.datetime_type,
  };
  const start = dateTime(
    first.value as PropsValues,
    dateTimeOptions,
    propSource,
  );
  const end = dateTime(
    first.end_value as PropsValues,
    dateTimeOptions,
    propSource,
  );
  if (start === null || end === null) {
    return null;
  }
  return {
    value: start,
    end_value: end,
  };
};

const mediaSelection: Transformer<
  BaseTransformOptions,
  string | null | PropsValues | Array<PropsValues>
> = (value, options) => {
  const { multiple = false } = options;
  if (!value || typeof value !== 'object' || !('selection' in value)) {
    return null;
  }
  const selection = value.selection;
  if (
    selection === null ||
    selection === undefined ||
    selection === '' ||
    (typeof selection === 'object' && Object.keys(selection).length === 0)
  ) {
    return null;
  }
  // Normalize to array so both single and multiple paths work uniformly,
  // and so downstream transforms (mainProperty) can iterate and sort by weight.
  const selectionValues = Array.isArray(selection)
    ? selection
    : Object.values(selection);

  if (selectionValues.length === 0) {
    return null;
  }

  return multiple ? selectionValues : selectionValues[0];
};

const transforms = {
  mainProperty,
  firstRecord,
  dateTime,
  dateRange,
  mediaSelection,
  cast,
  link,
};

export type Transforms = typeof transforms;

export default transforms;
