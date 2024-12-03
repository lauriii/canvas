import { a2p } from '@/local_packages/utils.js';

import Select from '@/components/form/components/Select';
import InputBehaviors from '@/components/form/inputBehaviors';

import type { Attributes } from '@/types/DrupalAttribute';

const DrupalSelect = ({
  attributes = {},
  options = [],
}: {
  attributes?: Attributes & {
    onChange?: (e: React.ChangeEvent<HTMLSelectElement>) => void;
  };
  options?: {
    value: string;
    label: string;
    selected: boolean;
    type: string;
  }[];
}) => {
  const defaultValue = options?.filter((option) => option.selected)?.[0]?.value;
  const { value, onChange, ...remainingAttributes } = attributes;
  return (
    <Select
      value={String(attributes.value || defaultValue)}
      onValueChange={(value: string) => {
        const syntheticEvent = {
          target: {
            value,
            name: attributes.name,
          },
        } as React.ChangeEvent<HTMLSelectElement>;
        onChange?.(syntheticEvent);
      }}
      options={options.map((option) => ({
        value: option.value,
        label: option.label,
      }))}
      attributes={a2p(remainingAttributes)}
    />
  );
};

export default InputBehaviors(DrupalSelect);
