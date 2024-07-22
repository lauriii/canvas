import {a2p} from '@/local_packages/utils.js';
import {useState} from "react";
import type {ChangeEvent} from "react";
import type * as React from "react";

const Textarea = (props: React.ComponentProps<any>) => {
  const {value = '', attributes = {}, wrapperAttributes = {}}: {value: string, attributes: any, wrapperAttributes: any} = props;
  const [theValue, setTheValue] = useState(value || attributes.value || '');
  const onChangeHandler = (e: ChangeEvent<HTMLInputElement>) => {
    setTheValue(e.target.value);
  }


  return (<div{ ...a2p(wrapperAttributes) }>
    <textarea { ...a2p(attributes) } onChange={onChangeHandler} defaultValue={theValue}>

    </textarea>
  </div>)
}

export default Textarea;
