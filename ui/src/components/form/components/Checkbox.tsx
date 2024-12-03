import { Checkbox as RadixThemesCheckbox } from '@radix-ui/themes';

import type { Attributes } from '@/types/DrupalAttribute';

const Checkbox = ({
  defaultChecked = false,
  attributes = {},
}: {
  defaultChecked?: boolean;
  attributes?: Attributes;
}) => {
  return (
    <RadixThemesCheckbox defaultChecked={defaultChecked} {...attributes} />
  );
};

export default Checkbox;
