import { a2p } from '@/local_packages/utils.js';

import { RadioGroup, RadioItem } from '@/components/form/components/Radio';
import InputBehaviors from '@/components/form/inputBehaviors';

import type { Attributes } from '@/types/DrupalAttribute';

const DrupalRadioGroup = ({
  attributes = {},
  renderChildren,
}: {
  attributes?: Attributes & {
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  };
  renderChildren?: React.ReactNode;
}) => {
  return (
    <RadioGroup
      value={attributes.value?.toString() || ''}
      onValueChange={(value: string) => {
        const syntheticEvent = {
          target: {
            value,
            name: attributes.name,
          },
        } as unknown as React.ChangeEvent<HTMLInputElement>;
        attributes?.onChange?.(syntheticEvent);
      }}
      attributes={a2p(
        attributes,
        {},
        {
          skipAttributes: ['value', 'onChange', 'onBlur'],
        },
      )}
    >
      {renderChildren}
    </RadioGroup>
  );
};

const DrupalRadioItem = ({ attributes = {} }: { attributes?: Attributes }) => {
  return (
    <RadioItem
      value={attributes.value?.toString() || ''}
      attributes={a2p(
        attributes,
        {},
        {
          skipAttributes: ['checked', 'type', 'value', 'onChange', 'onBlur'],
        },
      )}
    />
  );
};

const WrappedDrupalRadioGroup = InputBehaviors(DrupalRadioGroup);
const WrappedDrupalRadioItem = InputBehaviors(DrupalRadioItem);

export {
  WrappedDrupalRadioGroup as DrupalRadioGroup,
  WrappedDrupalRadioItem as DrupalRadioItem,
};
