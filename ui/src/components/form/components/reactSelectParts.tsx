import { useEffect, useState } from 'react';
import { components } from 'react-select';
import { CheckIcon, ChevronDownIcon, Cross2Icon } from '@radix-ui/react-icons';

import type {
  ClearIndicatorProps,
  DropdownIndicatorProps,
  OptionProps,
} from 'react-select';

type ReactSelectOption = { value: string; label: string };

/**
 * Custom dropdown indicator, sized like the chevron of other selects.
 */
export const DropdownIndicator = (
  props: DropdownIndicatorProps<ReactSelectOption, boolean>,
) => (
  <components.DropdownIndicator {...props}>
    <ChevronDownIcon width={15} height={15} />
  </components.DropdownIndicator>
);

/**
 * Custom clear indicator using Radix Cross2Icon.
 */
export const ClearIndicator = (
  props: ClearIndicatorProps<ReactSelectOption, boolean>,
) => (
  <components.ClearIndicator {...props}>
    <Cross2Icon width={12} height={12} />
  </components.ClearIndicator>
);

/**
 * Custom option that marks chosen options with a check.
 */
export const Option = (props: OptionProps<ReactSelectOption, boolean>) => (
  <components.Option {...props}>
    <span>{props.children}</span>
    {props.isSelected && <CheckIcon width={15} height={15} aria-hidden />}
  </components.Option>
);

/**
 * Gets the ID of the visible label of a hidden form element.
 *
 * A react-select replaces a hidden <select>, which the Drupal label points at.
 * Use the label as the react-select's accessible name.
 */
export const useLabelId = (id: unknown): string | undefined => {
  const [labelId, setLabelId] = useState<string>();
  useEffect(() => {
    if (!id) return;
    const label = document.querySelector(`label[for="${CSS.escape(`${id}`)}"]`);
    if (!label) return;
    label.id ||= `${id}--label`;
    setLabelId(label.id);
  }, [id]);
  return labelId;
};
