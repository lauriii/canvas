import { useRef } from 'react';
import { Flex, Slider } from '@radix-ui/themes';

import type { ReactNode } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

// Covers percentages, columns, opacity, rotation, font weights … but not e.g.
// timestamps, where one slider pixel would span thousands of values.
const MAX_SLIDER_RANGE = 1000;

/**
 * Whether a number input has a range that a slider can represent.
 */
export const hasSliderRange = (attributes: Attributes): boolean => {
  if (
    attributes.min === undefined ||
    attributes.max === undefined ||
    attributes.min === '' ||
    attributes.max === ''
  ) {
    return false;
  }
  const range = Number(attributes.max) - Number(attributes.min);
  return range > 0 && range <= MAX_SLIDER_RANGE;
};

/**
 * The slider step for a number input.
 *
 * Follows the native default of 1 when `step` is missing or invalid.
 * `step="any"` (decimals without a set scale) has no slider equivalent, so
 * use 100 steps across the range.
 */
export const getSliderStep = (attributes: Attributes): number => {
  if (attributes.step === 'any') {
    return (Number(attributes.max) - Number(attributes.min)) / 100;
  }
  const step = Number(attributes.step);
  return step > 0 ? step : 1;
};

/**
 * Renders a slider next to a number input that has a min and a max.
 *
 * The number input stays the form element and still allows exact values. A
 * slider drag sets the input's value, which fires the same change event as
 * typing.
 *
 * @see \Drupal\Core\Field\Plugin\Field\FieldWidget\NumberWidget
 */
const NumberSlider = ({
  attributes,
  children,
}: {
  attributes: Attributes;
  children: ReactNode;
}) => {
  const fieldRef = useRef<HTMLDivElement>(null);
  const min = Number(attributes.min);
  const max = Number(attributes.max);
  const value = Number(attributes.value);

  return (
    <Flex align="center" gap="3">
      <div ref={fieldRef} style={{ flex: '0 0 5rem' }}>
        {children}
      </div>
      <Slider
        size="1"
        min={min}
        max={max}
        step={getSliderStep(attributes)}
        value={[attributes.value === '' || isNaN(value) ? min : value]}
        disabled={!!attributes.disabled}
        // The number input is the labelled form element; avoid announcing the
        // same value twice.
        aria-hidden
        // Radix Themes passes this to the thumb, which takes keyboard focus.
        tabIndex={-1}
        // The number input comes first in the DOM, so that a lookup of the
        // field's first <input> finds it rather than the slider's own hidden
        // <input>, rendered inside forms. Show the slider first.
        // @see DrupalInputMultivalueForm
        style={{ order: -1 }}
        onValueChange={([newValue]) => {
          const input = fieldRef.current?.querySelector('input');
          if (input) {
            // TextField intercepts the native setter to dispatch a change
            // event.
            // @see interceptNativeSetter()
            input.value = `${newValue}`;
            // Like typing, also fire `input`: multivalue items listen to it.
            // @see DrupalInputMultivalueForm
            input.dispatchEvent(new Event('input', { bubbles: true }));
          }
        }}
      />
    </Flex>
  );
};

export default NumberSlider;
