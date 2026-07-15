import Toggle from '@/components/form/components/Toggle';

import type { ClientWidgetDefinition, ClientWidgetProps } from '../types';

/**
 * Native counterpart of `boolean_checkbox`, rendered as a toggle to match the
 * server-built form's presentation of boolean props.
 */
const BooleanCheckboxWidget = ({
  value,
  onChange,
  disabled,
  inputId,
  inputName,
}: ClientWidgetProps) => (
  <Toggle
    checked={Boolean(value)}
    onCheckedChange={(checked) => onChange(checked)}
    attributes={{ id: inputId, name: inputName, disabled }}
  />
);

export const booleanCheckboxWidget: ClientWidgetDefinition = {
  component: BooleanCheckboxWidget,
  codec: {
    // A boolean prop always has a value; unlike text inputs there is no
    // "empty" state to remove from the model.
    toModel: (widgetValue) => ({ resolved: Boolean(widgetValue) }),
    fromModel: (sourceValue, resolvedValue) =>
      Boolean(resolvedValue ?? sourceValue ?? false),
  },
};
