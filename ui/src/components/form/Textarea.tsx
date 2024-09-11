import { a2p } from '@/local_packages/utils.js';
import inputBehaviors from './inputBehaviors';
import type * as React from 'react';
import { TextArea } from '@radix-ui/themes';

const Textarea: React.FC<any> = (props: any) => {
  const {
    attributes = {},
    wrapperAttributes = {},
  }: { value: string; attributes: any; wrapperAttributes: any } = props;

  return (
    <div {...a2p(wrapperAttributes)}>
      <TextArea {...a2p(attributes)}></TextArea>
    </div>
  );
};

export default inputBehaviors(Textarea);
