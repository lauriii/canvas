import { a2p } from '@/local_packages/utils.js';

import Toggle from '@/components/form/components/Toggle';
import InputBehaviors from '@/components/form/inputBehaviors';

import type { Attributes } from '@/types/DrupalAttribute';

const DrupalToggle = ({
  attributes = {},
}: {
  attributes?: Attributes & {
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  };
}) => (
  <Toggle
    checked={!!attributes?.value}
    onCheckedChange={(value: boolean) => {
      const syntheticEvent = {
        target: {
          checked: value,
          name: attributes.name,
        },
      } as unknown as React.ChangeEvent<HTMLInputElement>;
      attributes?.onChange?.(syntheticEvent);
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
        'aria-checked': attributes.value ? 'true' : 'false',
      } as unknown as Omit<typeof attributes, 'onChange'>,
      {},
      { skipAttributes: ['value', 'onChange', 'type'] },
    )}
  />
);

export default InputBehaviors(DrupalToggle);
