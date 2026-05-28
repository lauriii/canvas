import { useEffect, useRef, useState } from 'react';
import ReactSelect, { components } from 'react-select';
import { ChevronDownIcon, Cross2Icon } from '@radix-ui/react-icons';

import { withRHF } from '@/components/form/react-hook-form/withRHF';
import { a2p } from '@/local_packages/utils';

import type {
  ActionMeta,
  ClearIndicatorProps,
  DropdownIndicatorProps,
  MultiValue,
  MultiValueRemoveProps,
} from 'react-select';
import type { Attributes } from '@/types/DrupalAttribute';

import styles from './DrupalSelectMultivalueForm.module.css';

interface SelectOption {
  value: string;
  label: string;
  selected: boolean;
  type: string;
}

export interface DrupalSelectMultivalueFormProps {
  attributes?: Attributes & {
    onChange?: (e: React.ChangeEvent<HTMLSelectElement>) => void;
    value?: string | string[];
    name?: string;
    id?: string;
    multiple?: boolean;
    'data-field-label'?: string;
    'data-cardinality'?: string | number;
  };
  options?: SelectOption[];
}

type ReactSelectOption = { value: string; label: string };

/**
 * Custom dropdown indicator using Radix ChevronDownIcon.
 */
const DropdownIndicator = (
  props: DropdownIndicatorProps<ReactSelectOption, true>,
) => (
  <components.DropdownIndicator {...props}>
    <ChevronDownIcon width={20} height={20} />
  </components.DropdownIndicator>
);

/**
 * Custom multi-value remove button using Radix Cross2Icon.
 */
const MultiValueRemove = (
  props: MultiValueRemoveProps<ReactSelectOption, true>,
) => (
  <components.MultiValueRemove {...props}>
    <Cross2Icon width={10} height={10} />
  </components.MultiValueRemove>
);

/**
 * Custom clear indicator using Radix Cross2Icon.
 */
const ClearIndicator = (
  props: ClearIndicatorProps<ReactSelectOption, true>,
) => (
  <components.ClearIndicator {...props}>
    <Cross2Icon width={12} height={12} />
  </components.ClearIndicator>
);

/**
 * Hidden native select element wrapped with withRHF.
 */
const HiddenSelect: React.FC<{
  attributes?: DrupalSelectMultivalueFormProps['attributes'];
  options?: SelectOption[];
}> = ({ attributes = {}, options = [] }) => {
  const nativeSelectProps = a2p(
    attributes,
    {},
    {
      skipAttributes: ['class', 'data-field-label'],
    },
  );

  // Ensure value is always an array for multiple select.
  const selectValue = nativeSelectProps.value;
  const normalizedValue = Array.isArray(selectValue)
    ? selectValue
    : selectValue
      ? [selectValue]
      : [];

  return (
    <select
      {...nativeSelectProps}
      value={normalizedValue}
      multiple
      className={styles.visuallyHiddenInput}
      aria-hidden="true"
      tabIndex={-1}
    >
      {options.map((opt, idx) => (
        <option key={idx} value={opt.value}>
          {opt.label}
        </option>
      ))}
    </select>
  );
};

// Wrap the native select using withRHF so Redux tracks it.
const HiddenSelectWithBehaviors = withRHF(HiddenSelect);

/**
 * DrupalSelectMultivalueForm component for select elements within multivalue
 * widgets that use the options_select widget.
 *
 * This component renders a React Select with multi-select support, displaying:
 * - Selected values as removable chips/tags at the top
 * - A dropdown with remaining options
 */
const DrupalSelectMultivalueForm = ({
  attributes = {},
  options = [],
}: DrupalSelectMultivalueFormProps) => {
  // Ref to the hidden native <select> element for form integration.
  const nativeSelectRef = useRef<HTMLSelectElement | null>(null);

  // Callback ref to capture the native select element after withRHF wraps it.
  const hiddenSelectContainerRef = useRef<HTMLDivElement | null>(null);

  // Find the native select wrapped by withRHF.
  useEffect(() => {
    if (hiddenSelectContainerRef.current) {
      const selectElement =
        hiddenSelectContainerRef.current.querySelector('select');
      if (selectElement) {
        nativeSelectRef.current = selectElement;
      }
    }
  }, []);

  // Parse cardinality from attributes (-1 means unlimited, positive integer means limited).
  const cardinality = attributes['data-cardinality']
    ? parseInt(String(attributes['data-cardinality']), 10)
    : -1;

  // Convert Drupal options format to react-select format (skip optgroup & _none).
  const reactSelectOptions: ReactSelectOption[] = options
    .filter((opt) => opt.type !== 'optgroup' && opt.value !== '_none')
    .map((opt) => ({ value: opt.value, label: opt.label }));

  // Build values from the options prop.
  const backendSelectedValues = options
    .filter((opt) => opt.selected && opt.value !== '_none')
    .map((opt) => ({ value: opt.value, label: opt.label }));

  // Local state provides immediate UI updates for ReactSelect.
  // Without it, selections don't appear until backend/Redux updates complete.
  const [localSelectedValues, setLocalSelectedValues] = useState<
    ReactSelectOption[]
  >(backendSelectedValues);

  /**
   * Handle changes from React Select.
   */
  const handleChange = (
    newValue: MultiValue<ReactSelectOption>,
    actionMeta: ActionMeta<ReactSelectOption>,
  ) => {
    // Update local state.
    setLocalSelectedValues([...newValue]);

    const el = nativeSelectRef.current;
    if (!el) return;

    // Update selected state on native select options.
    const selectedValueSet = new Set(newValue.map((opt) => opt.value));
    Array.from(el.options).forEach((option) => {
      option.selected = selectedValueSet.has(option.value);
    });

    const changeEvent = new Event('change', { bubbles: true });
    el.dispatchEvent(changeEvent);
  };

  const fieldLabel = String(attributes['data-field-label'] || '');
  const placeholderText = `Select ${fieldLabel}`;

  return (
    <div className={styles.container}>
      {/* Hidden native <select> wrapped with withRHF. */}
      <div ref={hiddenSelectContainerRef}>
        <HiddenSelectWithBehaviors
          attributes={{ ...attributes, 'data-is-multiselect': true }}
          options={options}
        />
      </div>

      {/* React Select for the custom multi-select UI */}
      <ReactSelect<ReactSelectOption, true>
        isMulti
        isClearable={true}
        options={reactSelectOptions}
        value={localSelectedValues}
        onChange={handleChange}
        placeholder={placeholderText}
        aria-label={placeholderText}
        classNamePrefix="canvas-select"
        closeMenuOnSelect={false}
        noOptionsMessage={() => 'No selection'}
        components={{
          DropdownIndicator,
          MultiValueRemove,
          ClearIndicator,
          IndicatorSeparator: () => null,
        }}
        isOptionDisabled={(option) => {
          // If cardinality is unlimited (-1), never disable options.
          if (cardinality === -1) {
            return false;
          }
          // If cardinality limit is reached, disable options that are not already selected.
          const isSelected = localSelectedValues.some(
            (v) => v.value === option.value,
          );
          const limitReached = localSelectedValues.length >= cardinality;
          return !isSelected && limitReached;
        }}
      />
    </div>
  );
};

export default DrupalSelectMultivalueForm;
