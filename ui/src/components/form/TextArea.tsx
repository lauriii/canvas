import { a2p } from '@/local_packages/utils.js';
import inputBehaviors from './inputBehaviors';
import type * as React from 'react';
import { TextArea as TextAreaRadixThemes } from '@radix-ui/themes';
import styles from './TextArea.module.css';

const TextArea: React.FC<any> = (props: any) => {
  const {
    attributes = {},
    wrapperAttributes = {},
  }: { value: string; attributes: any; wrapperAttributes: any } = props;

  return (
    <div {...a2p(wrapperAttributes)} className={styles.wrapper}>
      <TextAreaRadixThemes {...a2p(attributes)} />
    </div>
  );
};

export default inputBehaviors(TextArea);
