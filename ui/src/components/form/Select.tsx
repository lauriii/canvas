import { a2p } from '@/local_packages/utils.js';
import type * as React from 'react';
import inputBehaviors from '@/components/form/inputBehaviors';

const Select = (props: React.ComponentProps<any>) => {
  const { attributes, options } = props;

  return (
    <div>
      <select {...a2p(attributes)}>
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
