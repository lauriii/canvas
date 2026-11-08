import { useEffect, useRef, useState } from 'react';
import ReactSelect, { createFilter } from 'react-select';

import Select from '@/components/form/components/Select';
import {
  ClearIndicator,
  DropdownIndicator,
} from '@/components/form/components/selectIndicators';
import {
  formatTreeOptionLabel,
  noOptionsMessage,
  PATH_SEPARATOR,
} from '@/components/form/components/treeOptionLabel';
import { useFieldContext } from '@/components/form/contexts/FieldContext';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from '@/components/form/components/drupal/DrupalSelectMultivalueForm.module.css';

type Option = {
  value: string;
  label: string;
  selected: boolean;
  type: string;
};

// A dropdown longer than this is hard to scan without search.
const MIN_OPTIONS = 8;

/**
 * Whether a select is long or nested enough to need search and a tree.
 */
export const needsSearchableSelect = (
  attributes: Attributes,
  options: Option[],
): boolean =>
  !('multiple' in attributes) &&
  // Options from site data: how many there are varies.
  ('data-canvas-options-source' in attributes ||
    options.length >= MIN_OPTIONS ||
    options.some((option) => option.label.includes(PATH_SEPARATOR)));

/**
 * Renders a single-value select with search, and nested options as a tree.
 *
 * The <select> stays in the DOM, hidden, and remains the form element. Picking
 * an option sets the select's value, which fires the same change event as
 * picking an option from the dropdown.
 *
 * @see \Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget
 */
const SearchableSelect = ({
  attributes = {},
  options = [],
}: {
  attributes?: Attributes;
  options?: Option[];
}) => {
  const wrapperRef = useRef<HTMLDivElement>(null);
  const fieldContext = useFieldContext();
  const [labelledBy, setLabelledBy] = useState<string>();

  // The Drupal label points at the hidden select. Reuse it as the accessible
  // name of the search field.
  useEffect(() => {
    const id = attributes.id;
    if (!id) return;
    const label = document.querySelector(`label[for="${CSS.escape(`${id}`)}"]`);
    if (!label) return;
    label.id ||= `${id}--label`;
    setLabelledBy(label.id);
  }, [attributes.id]);

  const current = `${
    attributes.value ?? options.find((option) => option.selected)?.value ?? ''
  }`;
  // Shows the choice before the form state catches up.
  const [selected, setSelected] = useState(current);
  useEffect(() => setSelected(current), [current]);

  // Optional selects offer "None": clearing the field picks it.
  const noneOption = options.find((option) => option.value === '_none');
  const items = options
    .filter((option) => option.type !== 'optgroup' && option !== noneOption)
    .map((option) => ({ value: `${option.value}`, label: option.label }));

  return (
    <div ref={wrapperRef} className={styles.container}>
      <ReactSelect<{ value: string; label: string }, false>
        options={items}
        value={items.find((item) => item.value === selected) ?? null}
        isClearable={!!noneOption}
        isDisabled={!!attributes.disabled}
        placeholder="Search…"
        aria-labelledby={labelledBy}
        aria-describedby={attributes['aria-describedby'] as string | undefined}
        classNamePrefix="canvas-select"
        // Search the labels, not the IDs.
        filterOption={createFilter({ stringify: (option) => option.label })}
        formatOptionLabel={formatTreeOptionLabel}
        noOptionsMessage={noOptionsMessage}
        components={{
          DropdownIndicator,
          ClearIndicator,
          IndicatorSeparator: () => null,
        }}
        onChange={(item) => {
          const value = item?.value ?? noneOption?.value ?? '';
          setSelected(value);
          const select = wrapperRef.current?.querySelector('select');
          if (select) {
            // Select intercepts the native setter to dispatch a change event.
            // @see interceptNativeSetter()
            select.value = value;
          }
        }}
        onBlur={() =>
          // The hidden select never receives focus, so run its blur handling
          // (touched state, validation) when focus leaves the search field.
          fieldContext?.triggerBlur(
            wrapperRef.current?.querySelector('select') ?? undefined,
          )
        }
      />
      <div hidden>
        <Select attributes={attributes} options={options} />
      </div>
    </div>
  );
};

export default SearchableSelect;
