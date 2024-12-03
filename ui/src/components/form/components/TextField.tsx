import clsx from 'clsx';
import { TextField as RadixThemesTextField } from '@radix-ui/themes';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './TextField.module.css';

const TextField = ({
  className = '',
  attributes = {},
}: {
  className?: string;
  attributes?: Attributes;
}) => {
  return (
    <RadixThemesTextField.Root
      {...attributes}
      className={clsx(styles.root, className)}
    />
  );
};

export default TextField;
