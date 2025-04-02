import { a2p } from '@/local_packages/utils.js';

import Select from '@/components/form/components/Select';
import InputBehaviors from '@/components/form/inputBehaviors';
import { useRef, useEffect } from 'react';
import clsx from 'clsx';

import type { MutableRefObject } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

const DrupalSelect = ({
  attributes = {},
  options = [],
}: {
  attributes?: Attributes & {
    onChange?: (e: React.ChangeEvent<HTMLSelectElement>) => void;
  };
  options?: {
    value: string;
    label: string;
    selected: boolean;
    type: string;
  }[];
}) => {
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
    if (
      className &&
      selectRef?.current?.['className'] &&
      className !== selectRef?.current?.['className']
    ) {
      selectRef.current['className'] += ` ${className}`;
    }
    // Ignore because this only needs to be run once to add the initial classes
    // after the ref associated element is rendered.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <Select
      value={String(attributes.value || defaultValue)}
      onValueChange={(value: string) => {
        const syntheticEvent = {
          target: {
            value,
            name: attributes.name,
          },
        } as React.ChangeEvent<HTMLSelectElement>;
        onChange?.(syntheticEvent);
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
