import { Radio as RadixThemesRadio } from '@radix-ui/themes';

import type { Attributes } from '@/types/DrupalAttribute';

const Radio = ({
  value,
  attributes = {},
}: {
  value: string;
  attributes?: Attributes;
}) => {
  return <RadixThemesRadio value={value} {...attributes} />;
};

export default Radio;
