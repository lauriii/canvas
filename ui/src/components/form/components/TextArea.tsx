import { TextArea as TextAreaRadixThemes } from '@radix-ui/themes';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './TextArea.module.css';

const TextArea = ({
  value,
  attributes = {},
}: {
  value: string;
  attributes?: Attributes;
}) => {
  return (
    <TextAreaRadixThemes
      value={value}
      className={styles.root}
      {...attributes}
    />
  );
};

export default TextArea;
