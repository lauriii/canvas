import clsx from 'clsx';

import { a2p } from '@/local_packages/utils';

import { interceptNativeSetter } from './formChangeUtils';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './Select.module.css';

interface SelectProps {
  attributes?: Attributes;
  options?: Array<{
    value: string;
    label: string;
    selected: boolean;
    type: string;
  }>;
}
const Select: React.FC<SelectProps> = ({ attributes = {}, options = [] }) => {
  // Extract `value` before passing attributes to a2p, because a2p joins arrays
  // with spaces (intended for CSS classes). For <select multiple>, `value` must
  // be an array so React can properly control which options are selected.
  const { value, ...otherAttributes } = attributes;
  return (
    <select
      {...a2p(otherAttributes)}
      value={value}
      className={clsx(attributes.class || '', styles.select)}
      ref={(element) => {
        if (element) {
          interceptNativeSetter(element, {
            property: 'value',
          });
        }
      }}
    >
      {options.map((option, index) => (
        <option key={index} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  );
};

export default Select;
