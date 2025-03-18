import clsx from 'clsx';
import { TextField as RadixThemesTextField } from '@radix-ui/themes';
import { forwardRef, useEffect, useImperativeHandle } from 'react';
import type { MutableRefObject } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';
import styles from './TextField.module.css';

interface CustomAutocompleteEvent extends Event {
  detail: {
    ui: {
      item: {
        label: string;
        value: string;
      };
    };
  };
}

const TextFieldAutocomplete = forwardRef(
  (
    {
      className = '',
      attributes = {},
    }: {
      className?: string;
      attributes?: Attributes;
    },
    ref,
  ) => {
    // This attribute prevents the input from updating the store on change.
    // Without this, autocomplete search results will disappear moments after
    // they appear due to the component rerendering on value change.
    // The attribute is removed when a suggestion is picked, or the input is
    // blurred.
    // @see InputBehaviorsCommon in inputBehaviors.tsx where attributes.onChange
    // is defined.
    attributes['data-xb-no-update'] = '';

    const inputRef = ref as MutableRefObject<HTMLInputElement>;

    // Create a version of the ref that will appease Typescript.
    useImperativeHandle(ref, () => inputRef.current as HTMLInputElement);

    // This handler is in a separate method to accommodate the necessary
    // Typescript.
    const handleOnPlay = (e: Event & CustomAutocompleteEvent) => {
      if (!inputRef?.current) {
        return;
      }
      // After an autocomplete selection is made, remove the attribute that
      // prevents real time preview updates.
      inputRef.current.removeAttribute('data-xb-no-update');
      setTimeout(() => {
        // Call the onChange listener so the Redux store is updated.
        if (attributes?.onChange) {
          const event = new Event('change');
          inputRef.current.value = e.detail.ui.item.label;
          Object.defineProperty(event, 'target', {
            writable: false,
            value: inputRef.current,
          });
          if (typeof attributes?.onChange === 'function') {
            attributes.onChange(event);
          }
        }
      });
    };

    useEffect(() => {
      if (inputRef.current) {
        // When the jQuery autocompletesearch event occurs it is translated into a
        // native 'pause' event that can be handled with an on* attributes.
        // @see js/autocomplete.extend.js
        inputRef.current.onpause = (e: Event) => {
          if (inputRef?.current) {
            return;
          }
          // Set the attribute that prevents real time preview from updating,
          // which also prevents this component from re-rendering mid-search.
          inputRef.current.setAttribute('data-xb-no-update', '');
        };

        // When the jQuery autocompleteselect event occurs it is translated into a
        // native 'play' event that can be handled with an on* attributes.
        // @see js/autocomplete.extend.js
        inputRef.current.onplay = handleOnPlay as EventListener;

        // When a blur event occurs in a jQuery autocomplete element, it is
        // translated into a native 'ended' element so it can exist alongside
        // the onBlur handler added in inputBehaviors.tsx.
        // @see js/autocomplete.extend.js
        inputRef.current.onended = (e: Event) => {
          if (!inputRef?.current) {
            return;
          }
          // If the input is blurred, remove the attribute that prevents real
          // time preview updates.
          inputRef.current.removeAttribute('data-xb-no-update');
          if (attributes?.onChange) {
            const event = new Event('change');
            Object.defineProperty(event, 'target', {
              writable: false,
              value: inputRef.current,
            });
            if (typeof attributes?.onChange === 'function') {
              attributes.onChange(event);
            }
          }
        };
      }
      // Ignore because this only needs to be run once to add the event listeners.
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    return (
      <RadixThemesTextField.Root
        {...attributes}
        className={clsx(styles.root, className)}
        ref={inputRef}
      />
    );
  },
);

export default TextFieldAutocomplete;
