import { a2p } from '@/local_packages/utils';
import clsx from 'clsx';
import { useState } from 'react';
import styles from './Checkbox.module.css';
import type { Attributes } from '@/types/DrupalAttribute';

interface CheckboxTarget extends EventTarget {
  checked: boolean;
}

interface CheckboxEvent extends Event {
  target: CheckboxTarget;
}

interface JQueryProxyCheckboxEvent extends CheckboxEvent {
  detail?: {
    jqueryProxy?: boolean;
  };
}

const Checkbox = ({
  attributes = {},
}: {
  attributes?: Attributes & {
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  };
}) => {
  const [checked, setChecked] = useState(attributes?.checked || false);
  const changeCallback = (e: CheckboxEvent | JQueryProxyCheckboxEvent) => {
    setChecked(e.target.checked);
    const syntheticEvent = {
      target: {
        checked: e.target.checked,
        name: attributes?.name || 'noop',
      },
    } as unknown as React.ChangeEvent<HTMLInputElement>;
    attributes?.onChange?.(syntheticEvent);
  };

  return (
    <input
      {...a2p(attributes, {}, { skipAttributes: ['checked'] })}
      checked={checked}
      value={checked}
      className={clsx(attributes.class, styles.base, checked && styles.checked)}
      onChange={changeCallback}
      ref={(node) => {
        if (!node) {
          return;
        }
        node.addEventListener('change', ((e: JQueryProxyCheckboxEvent) => {
          // Some Drupal APIs use jQuery to change checkbox values, which are
          // acknowledged by the onChange listener, so those dispatches are
          // rerouted here.
          // @see jquery.overrides.js
          if (e?.detail?.jqueryProxy && e.target) {
            if (e.target.checked !== checked) {
              changeCallback(e);
            }
          }
        }) as EventListener);
      }}
    />
  );
};

export default Checkbox;
