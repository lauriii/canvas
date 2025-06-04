import clsx from 'clsx';
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
    <div className={styles.wrap}>
      <input {...attributes} className={clsx(styles.root, className)} />
    </div>
  );
};

export default TextField;
