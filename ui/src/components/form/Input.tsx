import { a2p } from '@/local_packages/utils.js';
import type * as React from 'react';
import inputBehaviors from './inputBehaviors';
import { TextField } from '@radix-ui/themes';

const Input = (props: React.ComponentProps<any>) => {
  const { attributes = {}, renderChildren = '' } = props;
  const unHandledTypes = ['submit', 'hidden'];

  return (
    <>
      {!unHandledTypes.includes(attributes?.type) && (
        <TextField.Root {...a2p(attributes)} mb="5" />
      )}
      {/* The a2p() process converts 'value to 'defaultValue', which is
          typically what React wants. Explicitly set the value on submit inputs
          since that is the text it displays. */}
      {unHandledTypes.includes(attributes?.type) && (
        <input {...a2p(attributes)} value={attributes.value || ''} />
      )}
      {renderChildren}
    </>
  );
};

export default inputBehaviors(Input);
