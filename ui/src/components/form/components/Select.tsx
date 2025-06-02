import clsx from 'clsx';
import styles from './Select.module.css';
import { a2p } from '@/local_packages/utils';
import type { Attributes } from '@/types/DrupalAttribute';

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
  return (
    <select
      {...a2p(attributes)}
      className={clsx(attributes.class || '', styles.select)}
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
