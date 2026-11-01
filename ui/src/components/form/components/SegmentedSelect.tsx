import { useEffect, useRef, useState } from 'react';
import { SegmentedControl } from '@radix-ui/themes';

import Select from '@/components/form/components/Select';

import type { Attributes } from '@/types/DrupalAttribute';

type Option = {
  value: string;
  label: string;
  selected: boolean;
  type: string;
};

// Sized to fit the sidebar width.
const MAX_OPTIONS = 4;
const MAX_TOTAL_LABEL_LENGTH = 32;

/**
 * Whether a select is short enough to render as a segmented control.
 */
export const fitsSegmentedControl = (
  attributes: Attributes,
  options: Option[],
): boolean =>
  !('multiple' in attributes) &&
  options.length >= 2 &&
  options.length <= MAX_OPTIONS &&
  options.reduce((length, option) => length + option.label.length, 0) <=
    MAX_TOTAL_LABEL_LENGTH;

/**
 * Renders a short single-value select as a segmented control.
 *
 * The <select> stays in the DOM, hidden, and remains the form element. A click
 * on a segment sets the select's value, which fires the same change event as
 * picking an option from the dropdown.
 *
 * @see \Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget
 */
const SegmentedSelect = ({
  attributes = {},
  options = [],
}: {
  attributes?: Attributes;
  options?: Option[];
}) => {
  const wrapperRef = useRef<HTMLDivElement>(null);
  const [labelledBy, setLabelledBy] = useState<string>();

  // The Drupal label points at the hidden select. Reuse it as the accessible
  // name of the segmented control.
  useEffect(() => {
    const id = attributes.id;
    if (!id) return;
    const label = document.querySelector(`label[for="${CSS.escape(`${id}`)}"]`);
    if (!label) return;
    label.id ||= `${id}--label`;
    setLabelledBy(label.id);
  }, [attributes.id]);

  const value = `${
    attributes.value ??
    options.find((option) => option.selected)?.value ??
    options[0]?.value ??
    ''
  }`;

  return (
    <div ref={wrapperRef} data-canvas-segmented-select>
      <SegmentedControl.Root
        size="1"
        value={value}
        disabled={!!attributes.disabled}
        aria-labelledby={labelledBy}
        onValueChange={(newValue) => {
          const select = wrapperRef.current?.querySelector('select');
          if (select) {
            // Select intercepts the native setter to dispatch a change event.
            // @see interceptNativeSetter()
            select.value = newValue;
          }
        }}
      >
        {options.map((option) => (
          <SegmentedControl.Item key={option.value} value={`${option.value}`}>
            {/* Drupal labels the empty option "- None -". */}
            {option.label.replace(/^- (.+) -$/, '$1')}
          </SegmentedControl.Item>
        ))}
      </SegmentedControl.Root>
      <div hidden>
        <Select attributes={attributes} options={options} />
      </div>
    </div>
  );
};

export default SegmentedSelect;
