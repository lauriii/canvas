import clsx from 'clsx';
import { TextField as RadixThemesTextField } from '@radix-ui/themes';
import { forwardRef } from 'react';

import type { ForwardedRef } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

import styles from './TextField.module.css';

const TextField = forwardRef(function TextField(
  {
    className = '',
    attributes = {},
  }: {
    className?: string;
    attributes?: Attributes;
  },
  ref: ForwardedRef<HTMLInputElement>,
) {
  return (
    <RadixThemesTextField.Root
      {...attributes}
      className={clsx(styles.root, className)}
      ref={ref}
    />
  );
});

export default TextField;
