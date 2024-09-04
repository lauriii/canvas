import { a2p } from '@/local_packages/utils.js';
import inputBehaviors from '@/components/form/inputBehaviors';
import './Select.css';
import type * as React from 'react';

const Select = (props: React.ComponentProps<any>) => {
  const { attributes, options } = props;

  const defaultValue = options.filter(
    (option: React.ComponentProps<any>) => option.selected,
  )?.[0]?.value;
  if (defaultValue && !attributes.value) {
    attributes.value = defaultValue;
  }

  return (
    <div>
      <select {...a2p(attributes)} className="selectElement">
        {options.map(
          (
            option: React.ComponentProps<any>,
            index: React.ComponentProps<any>,
          ) => (
            <option key={index} value={option.value}>
              {option.label}
            </option>
          ),
        )}
      </select>
    </div>
  );
};

export default inputBehaviors(Select);
