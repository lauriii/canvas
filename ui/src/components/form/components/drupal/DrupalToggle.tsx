import { useState } from 'react';

import Toggle from '@/components/form/components/Toggle';
import { useFieldContext } from '@/components/form/contexts/FieldContext';
import { withRHF } from '@/components/form/react-hook-form/withRHF';
import { a2p } from '@/local_packages/utils.js';

import type { Attributes } from '@/types/DrupalAttribute';

const DrupalToggle = ({
  attributes = {},
  defaultValue,
  currentValue,
}: {
  attributes?: Attributes;
  defaultValue?: string | number;
  currentValue?: string | number;
}) => {
  const [isChecked, setIsChecked] = useState(
    !!defaultValue || !!currentValue || false,
  );

  const fieldContext = useFieldContext();

  return (
    <Toggle
      checked={isChecked}
      onCheckedChange={(value: boolean) => {
        fieldContext?.triggerChange(value);
        setIsChecked(value);
      }}
      attributes={a2p(
        {
          ...attributes,
          // Setting the `aria-checked` attribute explicitly to avoid having it
          // end up as "checked" instead of "true" due to something that the Switch
          // primitive from Radix UI (used by the Toggle component) misinterprets
          // when processing the attributes it receives.
          // The `aria-checked` attribute needs to be set to "true" or "false".
          // @see https://w3c.github.io/aria/#aria-checked
          'aria-checked': isChecked ? 'true' : 'false',
        },
        {},
        { skipAttributes: ['value', 'onChange', 'type', 'checked'] },
      )}
    />
  );
};

export default withRHF(DrupalToggle);
