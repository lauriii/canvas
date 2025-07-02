import clsx from 'clsx';
import { useRef } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';
import styles from './TextField.module.css';
import { a2p } from '@/local_packages/utils';
import useMutationObserver from '@/hooks/useMutationObserver';

const { jQuery } = window;

const TextFieldAutocomplete = ({
  className = '',
  attributes = {},
}: {
  className?: string;
  attributes?: Attributes;
}) => {
  // This attribute prevents the input from updating the store on change.
  // Without this, autocomplete search results will disappear moments after
  // they appear due to the component rerendering on value change.
  // The attribute is removed when a suggestion is picked, or the input is
  // blurred.
  // @see InputBehaviorsCommon in inputBehaviors.tsx where attributes.onChange
  // is defined.
  attributes['data-xb-no-update'] = '';

  const inputRef = useRef<HTMLInputElement>(null);

  // This mutation observer responds to the addition of a
  // 'data-xb-autocomplete-selected' attribute, which will have the value of the
  // chosen autocomplete suggestion.
  useMutationObserver(
    inputRef,
    (mutations) => {
      mutations.forEach((record: MutationRecord) => {
        if (record?.attributeName === 'data-xb-autocomplete-selected') {
          if (
            record.target instanceof HTMLInputElement &&
            record.target.getAttribute('data-xb-autocomplete-selected')
          ) {
            const selection = record.target.getAttribute(
              'data-xb-autocomplete-selected',
            );
            if (selection) {
              record.target.value = selection;
            }

            // Remove the attribute to prevent multiple attempts to update the
            // store with the same value.
            record.target.removeAttribute('data-xb-autocomplete-selected');
            const changeEvent = new Event('change');
            Object.defineProperty(changeEvent, 'target', {
              writable: false,
              value: record.target,
            });

            if (typeof attributes.onChange === 'function') {
              attributes.onChange(changeEvent);
            }
          }
        }
      });
    },
    { attributes: true },
  );

  return (
    <div className={clsx(styles.wrap, styles.autocompleteWrap)}>
      <input
        {...a2p(attributes, {}, { skipAttributes: ['onBlur', 'onChange'] })}
        className={clsx(styles.root, styles.autocomplete, className)}
        ref={(node) => {
          if (node) {
            // @ts-ignore
            inputRef.current = node;
          }
        }}
        onChange={(e) => {
          // Default to setting the attribute that prevents preview and store
          // updates.
          if (inputRef.current) {
            inputRef.current.setAttribute('data-xb-no-update', 'true');
          }
          // Call the onChange listener, which will update the UI but not the
          // store or preview, due to the attribute set above.
          if (typeof attributes.onChange === 'function') {
            attributes.onChange(e);
          }

          const autocompleteDelay =
            inputRef.current &&
            !!jQuery.data(inputRef.current, 'ui-autocomplete')
              ? jQuery(inputRef.current).autocomplete(
                  'option',
                  'autocompleteDelay',
                )
              : 400;
          // Include a delayed change event that will fire after the event
          // listeners in autocomplete.extend.js have had a chance to
          // determine if suggestions are available and prevent store/preview
          // updates or if they aren't it updates the store/preview with what
          // has been typed.
          setTimeout(() => {
            if (
              inputRef.current &&
              !inputRef.current.hasAttribute('data-xb-no-update') &&
              typeof attributes.onChange === 'function'
            ) {
              attributes.onChange(e);
            }
          }, autocompleteDelay * 3);
        }}
        onBlur={(e) => {
          if (inputRef.current) {
            inputRef.current.removeAttribute('data-xb-no-update');
          }
          // As an additional assurance the value is sent to the store, an
          // additional onChange is triggered immediately after the store
          // preventing attribute is removed.
          if (typeof attributes.onChange === 'function') {
            attributes.onChange(e);
          }
          if (typeof attributes.onBlur === 'function') {
            attributes.onBlur(e);
          }
        }}
      />
    </div>
  );
};

export default TextFieldAutocomplete;
