import { components } from 'react-select';
import { ChevronDownIcon, Cross2Icon } from '@radix-ui/react-icons';

import type { ClearIndicatorProps, DropdownIndicatorProps } from 'react-select';

type ReactSelectOption = { value: string; label: string };

/**
 * Custom dropdown indicator using Radix ChevronDownIcon.
 */
export const DropdownIndicator = (
  props: DropdownIndicatorProps<ReactSelectOption, boolean>,
) => (
  <components.DropdownIndicator {...props}>
    <ChevronDownIcon width={20} height={20} />
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
