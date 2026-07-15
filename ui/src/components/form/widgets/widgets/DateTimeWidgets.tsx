import { Flex, Text } from '@radix-ui/themes';

import TextField from '@/components/form/components/TextField';

import type {
  ClientWidgetContext,
  ClientWidgetDefinition,
  ClientWidgetProps,
  WidgetCodec,
} from '../types';

/**
 * Native counterparts of the Drupal `datetime_default` and
 * `daterange_default` widgets.
 *
 * The codecs mirror the `dateTime` and `dateRange` transforms so the native
 * path persists the same model values as the server-widget path.
 */

interface DateTimeWidgetValue {
  date: string;
  time: string;
}

interface DateRangeWidgetValue {
  start: DateTimeWidgetValue;
  end: DateTimeWidgetValue;
}

/**
 * Reads the field storage's datetime type. A missing setting formats like
 * `datetime`, matching the `dateTime` transform's non-`date` branch.
 *
 * @see \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem::DATETIME_TYPE_DATE
 * @see \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem::DATETIME_TYPE_DATETIME
 */
const getDatetimeType = (context: ClientWidgetContext): 'date' | 'datetime' => {
  const storage = context.sourceTypeSettings.storage as
    | { datetime_type?: 'date' | 'datetime' }
    | undefined;
  return storage?.datetime_type === 'date' ? 'date' : 'datetime';
};

const asDateTimeValue = (value: unknown): DateTimeWidgetValue => {
  const record = (value ?? {}) as Partial<DateTimeWidgetValue>;
  return { date: record.date ?? '', time: record.time ?? '' };
};

const asDateRangeValue = (value: unknown): DateRangeWidgetValue => {
  const record = (value ?? {}) as Partial<DateRangeWidgetValue>;
  return {
    start: asDateTimeValue(record.start),
    end: asDateTimeValue(record.end),
  };
};

/**
 * Formats a `{date, time}` pair as the resolved model value: the plain date
 * string for date-only storage, or a UTC ISO string for datetime storage with
 * the time defaulting to noon when unset, exactly like the `dateTime`
 * transform.
 *
 * @see \Drupal\Component\Datetime\DateTimePlus::setDefaultDateTime
 */
const formatDateTimeValue = (
  value: DateTimeWidgetValue,
  type: 'date' | 'datetime',
): string | null => {
  if (!value.date) {
    return null;
  }
  if (type === 'date') {
    return value.date;
  }
  const time = value.time || '12:00:00';
  try {
    return new Date(`${value.date} ${time}+0000`).toISOString();
  } catch {
    return null;
  }
};

/**
 * Parses a stored model value back into the `{date, time}` widget pair. For
 * datetime storage the pair reflects the UTC date and time of the ISO string.
 */
const parseDateTimeValue = (
  modelValue: unknown,
  type: 'date' | 'datetime',
): DateTimeWidgetValue => {
  if (typeof modelValue !== 'string' || modelValue === '') {
    return { date: '', time: '' };
  }
  if (type === 'date') {
    return { date: modelValue, time: '' };
  }
  const parsed = new Date(modelValue);
  if (Number.isNaN(parsed.getTime())) {
    return { date: '', time: '' };
  }
  const iso = parsed.toISOString();
  return { date: iso.slice(0, 10), time: iso.slice(11, 19) };
};

const DateTimeInputs = ({
  value,
  onChange,
  type,
  disabled,
  required,
  errors,
  dateInputId,
  namePrefix,
  timeLabel,
}: {
  value: DateTimeWidgetValue;
  onChange: (value: DateTimeWidgetValue) => void;
  type: 'date' | 'datetime';
  disabled: boolean;
  required: boolean;
  errors: string | null;
  dateInputId: string;
  namePrefix: string;
  timeLabel: string;
}) => (
  <Flex gap="2">
    <TextField
      attributes={{
        id: dateInputId,
        name: `${namePrefix}[date]`,
        type: 'date',
        value: value.date,
        required,
        disabled,
        'aria-invalid': errors ? 'true' : undefined,
        onChange: (e: React.ChangeEvent<HTMLInputElement>) =>
          onChange({ ...value, date: e.target.value }),
      }}
    />
    {type === 'datetime' && (
      <TextField
        attributes={{
          id: `${dateInputId}--time`,
          name: `${namePrefix}[time]`,
          type: 'time',
          step: '1',
          value: value.time,
          disabled,
          'aria-label': timeLabel,
          'aria-invalid': errors ? 'true' : undefined,
          onChange: (e: React.ChangeEvent<HTMLInputElement>) =>
            onChange({ ...value, time: e.target.value }),
        }}
      />
    )}
  </Flex>
);

const DateTimeDefaultWidget = (props: ClientWidgetProps) => {
  const { value, onChange, disabled, required, errors, inputId, inputName } =
    props;
  return (
    <DateTimeInputs
      value={asDateTimeValue(value)}
      onChange={onChange}
      type={getDatetimeType(props)}
      disabled={disabled}
      required={required}
      errors={errors}
      dateInputId={inputId}
      namePrefix={`${inputName}[0][value]`}
      timeLabel="Time"
    />
  );
};

const dateTimeCodec: WidgetCodec = {
  toModel(widgetValue, context) {
    const formatted = formatDateTimeValue(
      asDateTimeValue(widgetValue),
      getDatetimeType(context),
    );
    return formatted === null ? null : { resolved: formatted };
  },
  fromModel(sourceValue, resolvedValue, context) {
    return parseDateTimeValue(
      resolvedValue ?? sourceValue,
      getDatetimeType(context),
    );
  },
};

export const dateTimeDefaultWidget: ClientWidgetDefinition = {
  component: DateTimeDefaultWidget,
  codec: dateTimeCodec,
};

const DateRangeDefaultWidget = (props: ClientWidgetProps) => {
  const { value, onChange, disabled, required, errors, inputId, inputName } =
    props;
  const rangeValue = asDateRangeValue(value);
  const type = getDatetimeType(props);
  return (
    <Flex direction="column" gap="2">
      <Flex direction="column" gap="1">
        <Text size="1" as="label" htmlFor={inputId}>
          Start
        </Text>
        <DateTimeInputs
          value={rangeValue.start}
          onChange={(start) => onChange({ ...rangeValue, start })}
          type={type}
          disabled={disabled}
          required={required}
          errors={errors}
          dateInputId={inputId}
          namePrefix={`${inputName}[0][value]`}
          timeLabel="Start time"
        />
      </Flex>
      <Flex direction="column" gap="1">
        <Text size="1" as="label" htmlFor={`${inputId}--end`}>
          End
        </Text>
        <DateTimeInputs
          value={rangeValue.end}
          onChange={(end) => onChange({ ...rangeValue, end })}
          type={type}
          disabled={disabled}
          required={required}
          errors={errors}
          dateInputId={`${inputId}--end`}
          namePrefix={`${inputName}[0][end_value]`}
          timeLabel="End time"
        />
      </Flex>
    </Flex>
  );
};

const dateRangeCodec: WidgetCodec = {
  toModel(widgetValue, context) {
    const type = getDatetimeType(context);
    const rangeValue = asDateRangeValue(widgetValue);
    const start = formatDateTimeValue(rangeValue.start, type);
    const end = formatDateTimeValue(rangeValue.end, type);
    // The dateRange transform requires both dates; a half-filled range
    // removes the prop from the model.
    if (start === null || end === null) {
      return null;
    }
    return { resolved: { value: start, end_value: end } };
  },
  fromModel(sourceValue, resolvedValue, context) {
    const type = getDatetimeType(context);
    // The stored source value keeps the field shape ({value, end_value});
    // the server-evaluated resolved value uses the SDC date-range schema
    // shape ({from, to}). Prefer the source shape and tolerate both, so the
    // widget round-trips regardless of which side last wrote the model.
    const modelValue = sourceValue ?? resolvedValue;
    const record =
      typeof modelValue === 'object' && modelValue !== null
        ? (modelValue as {
            value?: unknown;
            end_value?: unknown;
            from?: unknown;
            to?: unknown;
          })
        : {};
    return {
      start: parseDateTimeValue(record.value ?? record.from, type),
      end: parseDateTimeValue(record.end_value ?? record.to, type),
    };
  },
};

export const dateRangeDefaultWidget: ClientWidgetDefinition = {
  component: DateRangeDefaultWidget,
  codec: dateRangeCodec,
};
