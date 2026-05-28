import { useRef } from 'react';
import clsx from 'clsx';

import { useFieldContext } from '@/components/form/contexts/FieldContext';
import useMutationObserver from '@/hooks/useMutationObserver';
import { a2p } from '@/local_packages/utils';
import { resolveEntityUri } from '@/utils/transforms';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './TextField.module.css';

const { jQuery } = window;

const TextFieldAutocomplete = ({
  className = '',
  attributes = {},
}: {
  className?: string;
  attributes?: Attributes;
}) => {
  const formId = attributes['data-form-id'];
  // This attribute prevents the input from updating the store on change.
  // Without this, autocomplete search results will disappear moments after
  // they appear due to the component rerendering on value change.
  // The attribute is removed when a suggestion is picked, or the input is
  // blurred.
  attributes['data-canvas-no-update'] = '';

  const inputRef = useRef<HTMLInputElement>(null);
  const fieldContext = useFieldContext();

  // This mutation observer responds to the addition of a
  // 'data-canvas-autocomplete-selected' attribute, which will have the value of the
  // chosen autocomplete suggestion.
  useMutationObserver(
    inputRef,
    (mutations) => {
      mutations.forEach((record: MutationRecord) => {
        if (record?.attributeName === 'data-canvas-autocomplete-selected') {
          if (
            record.target instanceof HTMLInputElement &&
            record.target.getAttribute('data-canvas-autocomplete-selected')
          ) {
            const selection = record.target.getAttribute(
              'data-canvas-autocomplete-selected',
            );
            if (selection) {
              // @see \Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks::fieldWidgetSingleElementLinkDefaultWidgetFormAlter()
              fieldContext?.triggerChange(selection);

              if (record.target.dataset.canvasResolveEntityUri === 'true') {
                // Use setAttribute instead of .value. This means the input will
                // still *display* the suggestion label, but the stored value
                // will be the schema-required URI.
                record.target.setAttribute(
                  'value',
                  resolveEntityUri(selection),
                );
              }
            }

            // Remove the attribute to prevent multiple attempts to update the
            // store with the same value.
            record.target.removeAttribute('data-canvas-autocomplete-selected');
          }
        }
      });
    },
    { attributes: true },
  );

  return (
    <div className={clsx(styles.wrap, styles.autocompleteWrap)}>
      <input
        autoComplete="off"
        defaultValue={attributes.value}
        {...a2p(
          attributes,
          {},
          { skipAttributes: ['onBlur', 'onChange', 'value'] },
        )}
        className={clsx(styles.root, styles.autocomplete, className)}
        ref={(node) => {
          if (node) {
            // @ts-ignore
            inputRef.current = node;
          }
        }}
        onChange={() => {
          // Default to setting the attribute that prevents preview and store
          // updates.
          if (inputRef.current) {
            inputRef.current.setAttribute('data-canvas-no-update', 'true');
          }

          const autocompleteDelay =
            inputRef.current &&
            !!jQuery.data(inputRef.current, 'ui-autocomplete')
              ? jQuery(inputRef.current).autocomplete('option', 'delay')
              : 400;

          // Include a delayed change event that will fire after the event
          // listeners in autocomplete.extend.js have had a chance to
          // determine if suggestions are available and prevent store/preview
          // updates or if they aren't it updates the store/preview with what
          // has been typed.
          setTimeout(() => {
            const suggestionsOpen =
              inputRef.current &&
              jQuery(inputRef.current).data('ui-autocomplete') &&
              jQuery(inputRef.current).autocomplete('widget').is(':visible');
            if (inputRef.current && !suggestionsOpen) {
              const { value } = inputRef.current;
              inputRef.current.removeAttribute('data-canvas-no-update');
              fieldContext?.triggerChange(
                formId === 'component-instance-form'
                  ? resolveEntityUri(value)
                  : value,
                inputRef.current,
              );
            }
          }, autocompleteDelay * 2);
        }}
      />
    </div>
  );
};

export default TextFieldAutocomplete;
