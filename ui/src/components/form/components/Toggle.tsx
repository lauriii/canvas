import { Switch } from '@radix-ui/themes';

import type { Attributes } from '@/types/DrupalAttribute';

const Toggle = ({
  checked = false,
  onCheckedChange,
  attributes = {},
}: {
  checked?: boolean;
  onCheckedChange?: (checked: boolean) => void;
  attributes?: Attributes;
}) => {
  return (
    <Switch
      checked={checked}
      onCheckedChange={onCheckedChange}
      {...attributes}
    />
  );
};

export default Toggle;
