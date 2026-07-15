import TextArea from '@/components/form/components/TextArea';
import TextField from '@/components/form/components/TextField';

import { scalarCodec } from '../codecUtils';

import type { ClientWidgetDefinition, ClientWidgetProps } from '../types';

/**
 * Native counterparts of the single-line text-ish Drupal widgets:
 * `string_textfield`, `email_default`, and `number`.
 *
 * All three render an `<input>` through the shared TextField primitive with
 * a widget-specific `type` attribute; the scalar codec handles emptiness and
 * numeric casting.
 */
const makeTextWidget = (
  inputType: 'text' | 'email' | 'number',
): React.FC<ClientWidgetProps> => {
  const TextInputWidget = ({
    value,
    onChange,
    disabled,
    required,
    inputId,
    inputName,
    jsonSchema,
    errors,
  }: ClientWidgetProps) => {
    const numberAttributes =
      inputType === 'number'
        ? {
            // JSON schema numeric constraints map onto native input bounds.
            ...(jsonSchema.minimum !== undefined
              ? { min: String(jsonSchema.minimum) }
              : {}),
            ...(jsonSchema.maximum !== undefined
              ? { max: String(jsonSchema.maximum) }
              : {}),
            ...(jsonSchema.multipleOf !== undefined
              ? { step: String(jsonSchema.multipleOf) }
              : jsonSchema.type === 'number'
                ? { step: 'any' }
                : {}),
          }
        : {};
    return (
      <TextField
        attributes={{
          id: inputId,
          name: inputName,
          type: inputType,
          value: (value as string | number | undefined) ?? '',
          required,
          disabled,
          'aria-invalid': errors ? 'true' : undefined,
          maxLength: jsonSchema.maxLength as number | undefined,
          ...numberAttributes,
          onChange: (e: React.ChangeEvent<HTMLInputElement>) =>
            onChange(e.target.value),
        }}
      />
    );
  };
  TextInputWidget.displayName = `TextInputWidget(${inputType})`;
  return TextInputWidget;
};

export const stringTextfieldWidget: ClientWidgetDefinition = {
  component: makeTextWidget('text'),
  codec: scalarCodec,
};

export const emailDefaultWidget: ClientWidgetDefinition = {
  component: makeTextWidget('email'),
  codec: scalarCodec,
};

export const numberWidget: ClientWidgetDefinition = {
  component: makeTextWidget('number'),
  codec: scalarCodec,
};

/**
 * Native counterpart of `string_textarea`.
 */
const StringTextareaWidget = ({
  value,
  onChange,
  disabled,
  required,
  inputId,
  inputName,
  errors,
}: ClientWidgetProps) => (
  <TextArea
    value={(value as string | undefined) ?? ''}
    attributes={{
      id: inputId,
      name: inputName,
      rows: 5,
      required,
      disabled,
      'aria-invalid': errors ? 'true' : undefined,
      onChange: (e: React.ChangeEvent<HTMLTextAreaElement>) =>
        onChange(e.target.value),
    }}
  />
);

export const stringTextareaWidget: ClientWidgetDefinition = {
  component: StringTextareaWidget,
  codec: scalarCodec,
};
