import { a2p } from '@/local_packages/utils.js';
import type * as React from 'react';
import inputBehaviors from './inputBehaviors';
import './UrlInput.css';
const UrlInput = (props: React.ComponentProps<any>) => {
  const { attributes = {}, renderChildren = '' } = props;
  return (
    <div>
      <input
        {...a2p(attributes)}
        value={attributes.value || ''}
        className="urlInputElement"
      />
      {renderChildren}
    </div>
  );
};

export default inputBehaviors(UrlInput);
