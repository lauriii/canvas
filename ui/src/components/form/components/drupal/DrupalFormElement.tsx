import clsx from 'clsx';

import InputDescription from '@/components/form/components/drupal/InputDescription';
import { a2p } from '@/local_packages/utils.js';

import type { Attributes } from '@/types/DrupalAttribute';

// @todo Import styles in a standalone <FormElement> component.
// https://www.drupal.org/i/3491293
import styles from '../FormElement.module.css';

export type Description = {
  content?: string;
  attributes?: Attributes;
};

interface FormElementProps {
  attributes?: Attributes;
  errors: string | null;
  prefix: string | null;
  suffix: string | null;
  required: boolean | null;
  type: string | null;
  name: string | null;
  label: string | null;
  labelDisplay: string;
  description?: Description | string | false;
  descriptionDisplay?: 'before' | 'after' | 'invisible';
  disabled: string | null;
  titleDisplay: string;
  children: string | null;
  renderChildren: string | any[] | null;
}

const DrupalFormElement = ({
  attributes = {},
  errors = '',
  prefix = '',
  suffix = '',
  required = false,
  type = '',
  name,
  label = '',
  labelDisplay = '',
  description = '',
  descriptionDisplay = 'after',
  disabled = '',
  titleDisplay = '',
  children = '',
  renderChildren = '',
}: FormElementProps) => {
  const classes = clsx(
    'js-form-item',
    'form-item',
    `js-form-type-${type}`,
    `form-type-${type}`,
    `js-form-item-${name}`,
    `form-item-${name}`,
    !['after', 'before'].includes(titleDisplay) ? 'form-no-label' : '',
    disabled === 'disabled' ? 'form-disabled' : '',
    errors ? 'form-item--error' : '',
    // @todo Add styles below in a standalone <FormElement> component.
    // https://www.drupal.org/i/3491293
    styles.root,
    type === 'checkbox' && styles.checkbox,
    type === 'radio' && styles.radio,
  );

  return (
    // @todo Extract to a standalone <FormElement> component.
    // https://www.drupal.org/i/3491293
    <div {...a2p(attributes, { class: classes })}>
      {['before', 'invisible'].includes(labelDisplay) && label}
      {prefix && prefix.length > 0 && (
        <span className="field-prefix">{prefix}</span>
      )}
      <InputDescription
        description={description}
        descriptionDisplay={descriptionDisplay}
      >
        {renderChildren}
        {suffix && suffix.length > 0 && (
          <span className="field-suffix">{suffix}</span>
        )}
        {['after'].includes(labelDisplay) && label}
        {errors && (
          <div className="form-item--error-message form-item-errors">
            {errors}
          </div>
        )}
      </InputDescription>
    </div>
  );
};

export default DrupalFormElement;
