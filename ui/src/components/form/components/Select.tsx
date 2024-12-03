import type { Attributes } from '@/types/DrupalAttribute';

import { Select as SelectRadixThemes } from '@radix-ui/themes';

import styles from './Select.module.css';

const Select = ({
  value,
  onValueChange,
  options,
  attributes,
}: {
  value: string;
  onValueChange: (value: string) => void;
  options: { value: string; label: string }[];
  attributes: Attributes;
}) => {
  const { id, ...remainingAttributes } = attributes;
  return (
    <SelectRadixThemes.Root
      value={value}
      {...remainingAttributes}
      onValueChange={onValueChange}
    >
      <SelectRadixThemes.Trigger
        {...(id && { id: id as string })}
        className={styles.trigger}
      />
      <SelectRadixThemes.Content>
        {options?.map((option, index) => (
          <SelectRadixThemes.Item key={index} value={option.value}>
            {option.label}
          </SelectRadixThemes.Item>
        ))}
      </SelectRadixThemes.Content>
    </SelectRadixThemes.Root>
  );
};

export default Select;
