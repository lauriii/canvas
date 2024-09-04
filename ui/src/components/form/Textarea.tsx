import { a2p } from '@/local_packages/utils.js';
import inputBehaviors from './inputBehaviors';
import { useState } from 'react';
import type { ChangeEvent } from 'react';
import type * as React from 'react';
import { TextArea } from '@radix-ui/themes';

const Textarea: React.FC<any> = (props: any) => {
  const {
    value = '',
    attributes = {},
    wrapperAttributes = {},
  }: { value: string; attributes: any; wrapperAttributes: any } = props;
  const [theValue, setTheValue] = useState(value || attributes.value || '');
  const onChangeHandler = (e: ChangeEvent<HTMLInputElement>) => {
    setTheValue(e.target.value);
  };

  return (
    <div {...a2p(wrapperAttributes)}>
      <TextArea
        {...a2p(attributes)}
        onChange={onChangeHandler}
        value={theValue}
      ></TextArea>
    </div>
  );
};

export default inputBehaviors(Textarea);
