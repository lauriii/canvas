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
      attributes,
      {},
      { skipAttributes: ['value', 'onChange', 'type'] },
    )}
  />
);

export default InputBehaviors(DrupalToggle);
