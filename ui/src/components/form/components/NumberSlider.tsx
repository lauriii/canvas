import { useRef } from 'react';
import { Flex, Slider } from '@radix-ui/themes';

import type { ReactNode } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

// The most values a slider offers. Covers e.g. 0–10000, or 0–100000 in steps
// of 10 (`multipleOf: 10`), but not timestamps, where one slider pixel would
// span millions of values. The number input next to the slider still accepts
// exact values.
const MAX_SLIDER_POSITIONS = 10000;

/**
 * Whether a number input has a range that a slider can represent.
 *
 * A component sets the step with `multipleOf`.
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::buildComponentInstanceForm()
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
  return range > 0 && range / getSliderStep(attributes) <= MAX_SLIDER_POSITIONS;
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
  // Room for the unit that the number input shows, if any.
  // @see TextField
  const unitLength = `${attributes['data-canvas-unit'] ?? ''}`.length;

  return (
    <Flex align="center" gap="3">
      <div ref={fieldRef} style={{ flex: `0 0 calc(5rem + ${unitLength}ch)` }}>
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
