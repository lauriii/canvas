import { a2p } from '@/local_packages/utils.js';

import Select from '@/components/form/components/Select';
import InputBehaviors from '@/components/form/inputBehaviors';
import { useRef, useEffect } from 'react';
import clsx from 'clsx';

import type { MutableRefObject } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

interface DrupalSelectProps {
  attributes?: Attributes & {
    onChange?: (e: React.ChangeEvent<HTMLSelectElement>) => void;
    value?: string;
    name?: string;
    class?: string;
  };
  options?: Array<{
    value: string;
    label: string;
    selected: boolean;
    type: string;
  }>;
}

const DrupalSelect = ({ attributes = {}, options = [] }: DrupalSelectProps) => {
  const selectRef: MutableRefObject<HTMLButtonElement | null> =
    useRef<HTMLButtonElement | null>(null);
  const className = clsx(attributes.class);

  const defaultValue = options?.filter((option) => option.selected)?.[0]?.value;
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const { value, onChange, ...remainingAttributes } = attributes;
  // The useEffect ensures the ref is now associated with a DOM element so we
  // can ensure that classes meant to appear on the actual input actually appear
  // there, as Radix does not reliably provide the means to do this via props.
  // @see https://github.com/radix-ui/primitives/issues/3240
  useEffect(() => {
    if (!selectRef.current) {
      return;
    }

    // Add classes to the trigger button if they exist and aren't already applied
    if (className && !selectRef.current.className.includes(className)) {
      selectRef.current.className += ` ${className}`;
    }

    // Radix does not directly make the `<select>` element available for ref use.
    // However, some JS functionality expects attributes to be set on this
    // element. We access it by using the known ref as the basis of q query to
    // get its corresponding hidden `<select>`.
    const timeout = setTimeout(() => {
      if (!selectRef?.current) {
        return;
      }
      const hiddenSelect =
        selectRef.current.parentElement?.querySelector<HTMLSelectElement>(
          'select[aria-hidden]',
        );
      if (hiddenSelect) {
        Object.entries(attributes).forEach(([key, value]) => {
          if (key.startsWith('data-') && typeof value === 'string') {
            hiddenSelect.setAttribute(key, value);
          }
        });

        // Set the value on the hidden select.
        hiddenSelect.value = attributes.value ?? defaultValue;
        if (hiddenSelect.parentElement) {
          setTimeout(() => {
            window.Drupal.attachBehaviors(
              hiddenSelect.parentElement as HTMLElement,
            );
          });
        }
      }
    });
    return () => clearTimeout(timeout);
  }, [attributes, className, defaultValue]);

  return (
    <Select
      value={attributes.value ?? defaultValue}
      onValueChange={(newValue: string) => {
        if (!selectRef.current?.parentElement) {
          return;
        }

        const hiddenSelect =
          selectRef.current.parentElement.querySelector<HTMLSelectElement>(
            'select[aria-hidden]',
          );
        if (!hiddenSelect) return;

        hiddenSelect.value = newValue;

        if (onChange) {
          const syntheticEvent = {
            target: {
              value: newValue,
              name: attributes.name,
            },
          } as React.ChangeEvent<HTMLSelectElement>;
          onChange(syntheticEvent);
        }
      }}
      options={options.map((option) => ({
        value: option.value,
        label: option.label,
      }))}
      attributes={a2p(remainingAttributes)}
      ref={selectRef}
    />
  );
};

export default InputBehaviors(DrupalSelect);
