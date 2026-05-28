import { forwardRef } from 'react';
import clsx from 'clsx';

import type { ReactNode } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

import styles from './Form.module.css';

const Form = forwardRef<
  HTMLFormElement,
  {
    children?: ReactNode;
    attributes?: Attributes;
    className?: string;
  }
>(({ attributes = {}, children = null, className = '' }, ref) => {
  return (
    <form ref={ref} className={clsx(styles.root, className)} {...attributes}>
      {children}
    </form>
  );
});

export default Form;
