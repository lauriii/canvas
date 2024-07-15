import { a2p } from '@/local_packages/utils.js';
import type { ChangeEvent } from "react";
import { useState} from "react";
import type * as React from 'react';

const Input = (props: React.ComponentProps<any>) => {
  const { attributes = {}, renderChildren = '' } = props;
  const [value, setValue] = useState("");
  const onChangeHandler = (e: ChangeEvent<HTMLInputElement>) => {
    setValue(e.target.value);
  }

  return (
    <>
      {attributes?.type === 'submit' && <input  {...a2p(attributes)} onChange={onChangeHandler} />}
      {attributes?.type !== 'submit' && <input  {...a2p(attributes)} onChange={onChangeHandler} value={value} />}
      {renderChildren}
    </>
  );
};

export default Input;
