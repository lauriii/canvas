import { useEffect, useRef, useState } from 'react';
import * as radixIcons from '@radix-ui/react-icons';
import { SegmentedControl } from '@radix-ui/themes';

import Select from '@/components/form/components/Select';
import { useFieldContext } from '@/components/form/contexts/FieldContext';

import type { ComponentType } from 'react';
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

// An icon takes about as much room as two characters.
const ICON_LABEL_LENGTH = 2;

/**
 * The icons of a select's options, by option value.
 *
 * The component schema names them with `x-canvas-enum-icons`: a map of enum
 * value to the name of a Radix icon, such as `text-align-left`. An option
 * without an icon, or with an unknown icon name, keeps its label.
 *
 * @see https://www.radix-ui.com/icons
 * @see \Drupal\canvas\ComponentMetadataRequirementsChecker
 */
export const optionIcons = (
  attributes: Attributes,
): Record<string, ComponentType> => {
  let names: Record<string, string> = {};
  try {
    names = JSON.parse(`${attributes['data-canvas-option-icons'] ?? '{}'}`);
  } catch {
    // Not JSON: no icons.
  }
  const icons: Record<string, ComponentType> = {};
  Object.entries(names).forEach(([value, name]) => {
    // `text-align-left` is exported as `TextAlignLeftIcon`.
    const exportName = `${`${name}`.replace(/(^|-)(\w)/g, (_match, _dash, letter) => letter.toUpperCase())}Icon`;
    // @todo Load the icon set on demand: looking icons up by name bundles all of them.
    const icon = (radixIcons as Record<string, unknown>)[exportName];
    if (icon) {
      icons[value] = icon as ComponentType;
    }
  });
  return icons;
};

/**
 * Whether a select is short enough to render as a segmented control.
 */
export const fitsSegmentedControl = (
  attributes: Attributes,
  options: Option[],
): boolean => {
  const icons = optionIcons(attributes);
  return (
    !('multiple' in attributes) &&
    options.length >= 2 &&
    options.length <= MAX_OPTIONS &&
    options.reduce(
      (length, option) =>
        length +
        (icons[option.value] ? ICON_LABEL_LENGTH : option.label.length),
      0,
    ) <= MAX_TOTAL_LABEL_LENGTH
  );
};

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
  const fieldContext = useFieldContext();
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

  const icons = optionIcons(attributes);

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
        // Forward what the hidden select carries for the visible control: the
        // field description and the invalid-field outline.
        aria-describedby={attributes['aria-describedby'] as string | undefined}
        data-invalid-prop-value={attributes['data-invalid-prop-value']}
        onBlur={(e) => {
          // The hidden select never receives focus, so run its blur handling
          // (touched state, validation) when focus leaves the segments.
          if (!e.currentTarget.contains(e.relatedTarget)) {
            fieldContext?.triggerBlur(
              wrapperRef.current?.querySelector('select') ?? undefined,
            );
          }
        }}
        onValueChange={(newValue) => {
          const select = wrapperRef.current?.querySelector('select');
          if (select) {
            // Select intercepts the native setter to dispatch a change event.
            // @see interceptNativeSetter()
            select.value = newValue;
          }
        }}
      >
        {options.map((option) => {
          // Drupal labels the empty option "- None -".
          const label = option.label.replace(/^- (.+) -$/, '$1');
          const Icon = icons[option.value];
          return (
            <SegmentedControl.Item
              key={option.value}
              value={`${option.value}`}
              // An icon replaces the label, which stays as the tooltip and the
              // accessible name.
              title={Icon && label}
              aria-label={Icon && label}
            >
              {Icon ? <Icon /> : label}
            </SegmentedControl.Item>
          );
        })}
      </SegmentedControl.Root>
      <div hidden>
        <Select attributes={attributes} options={options} />
      </div>
    </div>
  );
};

export default SegmentedSelect;
