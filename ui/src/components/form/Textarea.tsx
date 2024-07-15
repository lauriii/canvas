import {a2p} from '@/local_packages/utils.js';
import {useEffect} from 'react';

const Textarea = ({attributes = {}, wrapperAttributes = {}}) => {
  useEffect(() => {

  });

  return (<div{ ...a2p(wrapperAttributes) }>
    <textarea { ...a2p(attributes) }  >

    </textarea>
  </div>)
}

export default Textarea;
