import { useId } from 'react';
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
  const props = a2p(attributes);
  // The unit of a number prop, such as "px": an SDC sets it with
  // `x-canvas-unit`. It shows at the end of the input and is the input's
  // accessible description. The value stays the bare number.
  // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::buildComponentInstanceForm()
  const unit = props['data-canvas-unit'];
  const unitId = useId();
  return (
    <div className={clsx(styles.wrap, unit && styles.withUnit)}>
      <input
        autoComplete="off"
        {...props}
        {...(unit && {
          'aria-describedby': clsx(props['aria-describedby'], unitId),
          // Keep the value and the spin buttons clear of the unit.
          style: { paddingInlineEnd: `calc(${unit.length}ch + 14px)` },
        })}
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
      {unit && (
        <span id={unitId} className={styles.unit}>
          {unit}
        </span>
      )}
    </div>
  );
};

export default TextField;
