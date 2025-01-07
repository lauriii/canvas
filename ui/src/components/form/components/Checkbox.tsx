import { Checkbox as RadixThemesCheckbox } from '@radix-ui/themes';

import type { Attributes } from '@/types/DrupalAttribute';

const Checkbox = ({
  checked = false,
  onCheckedChange,
  attributes = {},
}: {
  checked?: boolean;
  onCheckedChange?: (checked: boolean) => void;
  attributes?: Attributes;
}) => {
  return (
    <RadixThemesCheckbox
      checked={checked}
      onCheckedChange={onCheckedChange}
      {...attributes}
    />
  );
};

export default Checkbox;
