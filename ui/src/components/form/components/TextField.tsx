import clsx from 'clsx';

import { a2p } from '@/local_packages/utils';

import { interceptNativeSetter } from './formChangeUtils';

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
      <input
        autoComplete="off"
        {...a2p(attributes)}
        className={clsx(styles.root, className)}
        ref={(element) => {
          if (element) {
            interceptNativeSetter(element, {
              property: 'value',
              skipSet: (newValue: string) =>
                attributes?.type === 'number' && newValue === 'NaN',
            });
          }
        }}
      />
    </div>
  );
};

export default TextField;
