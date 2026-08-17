import clsx from 'clsx';

import {
  EMPTY_OPTION_VALUE,
  getEmptyOptionLabel,
} from '@/components/form/components/selectEmptyOption';
import { a2p } from '@/local_packages/utils';

import { interceptNativeSetter } from './formChangeUtils';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './Select.module.css';

interface SelectProps {
  attributes?: Attributes;
  options?: Array<{
    value: string;
    label: string;
    selected: boolean;
    type: string;
  }>;
}
const Select: React.FC<SelectProps> = ({ attributes = {}, options = [] }) => {
  // Extract `value` before passing attributes to a2p, because a2p joins arrays
  // with spaces (intended for CSS classes). For <select multiple>, `value` must
  // be an array so React can properly control which options are selected.
  const { value, ...otherAttributes } = attributes;

  // A single-select that carries Drupal's `_none` sentinel can be empty. That
  // empty state is presented as the absence of a value rather than as one more
  // option, so an author can tell "unset" from "set" without opening the list.
  const isMultiple = 'multiple' in attributes;
  const hasEmptyOption =
    !isMultiple &&
    options.some((option) => option.value === EMPTY_OPTION_VALUE);
  const currentValue =
    value ?? options.find((option) => option.selected)?.value;
  // A select with no value at all falls back to its first option, which is the
  // sentinel, so treat that the same as an explicit `_none`.
  const isEmpty =
    currentValue === EMPTY_OPTION_VALUE ||
    currentValue === undefined ||
    currentValue === '';
  const emptyOptionLabel = getEmptyOptionLabel(
    !!attributes.required,
    hasEmptyOption && isEmpty,
  );

  return (
    <select
      {...a2p(otherAttributes)}
      value={value}
      // Conveys the empty state to CSS and to tests without relying on the
      // muted color alone. The option label carries it for assistive tech.
      data-canvas-value-state={
        hasEmptyOption ? (isEmpty ? 'unset' : 'set') : undefined
      }
      className={clsx(attributes.class || '', styles.select)}
      ref={(element) => {
        if (element) {
          interceptNativeSetter(element, {
            property: 'value',
          });
        }
      }}
    >
      {options.map((option, index) =>
        hasEmptyOption && option.value === EMPTY_OPTION_VALUE ? (
          // Rendered apart from the real choices (see the CSS rule keyed off
          // this attribute) so it does not read as one more thing to pick.
          <option
            key={index}
            value={option.value}
            data-canvas-empty-option="true"
          >
            {emptyOptionLabel}
          </option>
        ) : (
          <option key={index} value={option.value}>
            {option.label}
          </option>
        ),
      )}
    </select>
  );
};

export default Select;
