import { a2p } from '@/local_packages/utils.js';
import type * as React from 'react';
import inputBehaviors from "./inputBehaviors";

const Input = (props: React.ComponentProps<any>) => {
  const { attributes = {}, renderChildren = '' } = props;

  return (
    <>
      {attributes?.type !== 'submit' && <input {...a2p(attributes)}  /> }
      {/* The a2p() process converts 'value to 'defaultValue', which is
          typically what React wants. Explicitly set the value on submit inputs
          since that is the text it displays. */}
      {attributes?.type === 'submit' && <input  {...a2p(attributes)} value={attributes.value || ''} />}
      {renderChildren}
    </>
  );
};

export default inputBehaviors(Input)
