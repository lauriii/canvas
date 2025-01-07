import { RadioGroup as RadixThemesRadioGroup } from '@radix-ui/themes';

import type { Attributes } from '@/types/DrupalAttribute';

const RadioGroup = ({
  value,
  onValueChange,
  attributes = {},
  children,
}: {
  value: string;
  onValueChange?: (value: string) => void;
  attributes?: Attributes;
  children?: React.ReactNode;
}) => {
  return (
    <RadixThemesRadioGroup.Root
      value={value}
      onValueChange={onValueChange}
      {...attributes}
    >
      {children}
    </RadixThemesRadioGroup.Root>
  );
};

const RadioItem = ({
  value,
  attributes = {},
}: {
  value: string;
  attributes?: Attributes;
}) => {
  return <RadixThemesRadioGroup.Item value={value} {...attributes} />;
};

export { RadioItem, RadioGroup };
