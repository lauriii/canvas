import clsx from 'clsx';
import parse from 'html-react-parser';

import { a2p } from '@/local_packages/utils';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './InputDescription.module.css';

export interface Description {
  content?: string;
  attributes?: Attributes;
}

export interface InputDescriptionProps {
  children: React.ReactNode;
  description?: Description | string | false;
  descriptionDisplay?: 'before' | 'after' | 'invisible';
  descriptionAttributes?: Attributes;
}

/**
 * A component that wraps input elements and handles description display.
 *
 * This abstracts the description rendering logic that was previously duplicated
 * in InputBehaviorsCommon and DrupalFormElement.
 */
const InputDescription: React.FC<InputDescriptionProps> = ({
  children,
  description,
  descriptionDisplay = 'before',
  ...descriptionAttributes
}) => {
  if (!description) {
    return <>{children}</>;
  }

  const descriptionClassesArray = [
    'description',
    descriptionDisplay === 'invisible' ? 'visually-hidden' : '',
  ];
  let unwrapped = false;
  if (
    'class' in descriptionAttributes &&
    // @ts-ignore
    descriptionAttributes['class'].includes('description-unwrapped')
  ) {
    unwrapped = true;
  }
  const descriptionClasses = clsx(
    unwrapped && 'description-unwrapped',
    unwrapped && styles.descriptionUnwrapped,
    descriptionClassesArray,
    styles.description,
  );

  var descriptionElement;
  if (typeof description === 'string') {
    descriptionElement = (
      <div {...a2p({}, { className: descriptionClasses })}>
        {parse(description)}
      </div>
    );
  } else if (typeof description.content === 'string') {
    descriptionElement = (
      <div {...a2p(description.attributes, { className: descriptionClasses })}>
        {parse(description.content)}{' '}
      </div>
    );
  } else {
    descriptionElement = (
      <div {...a2p(description.attributes, { className: descriptionClasses })}>
        {description.content}{' '}
      </div>
    );
  }

  return (
    <>
      {descriptionDisplay === 'before' && descriptionElement}
      {children}
      {['after', 'invisible'].includes(descriptionDisplay) &&
        descriptionElement}
    </>
  );
};

export default InputDescription;
