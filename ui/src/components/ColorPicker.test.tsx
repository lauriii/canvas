import { describe, expect, it, vi } from 'vitest';
import { Provider as TooltipProvider } from '@radix-ui/react-tooltip';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';

import ColorPicker from './ColorPicker';

import type { BrandKitColorValue } from '@/types/CodeComponent';

const TEST_VALUE: BrandKitColorValue = {
  colorSpace: 'srgb',
  components: [0.5, 0.75, 1],
  alpha: 0.555555,
  hex: '#80bfff',
};

const renderColorPicker = (
  props: {
    value?: BrandKitColorValue;
    onChange?: (value: BrandKitColorValue) => void;
    onValidityChange?: (isValid: boolean) => void;
  } = {},
) =>
  render(
    <Theme>
      <TooltipProvider delayDuration={0}>
        <ColorPicker
          value={props.value ?? TEST_VALUE}
          onChange={props.onChange ?? vi.fn()}
          onValidityChange={props.onValidityChange ?? vi.fn()}
        />
      </TooltipProvider>
    </Theme>,
  );

describe('ColorPicker', () => {
  it('rounds external alpha values to two decimal places in the input', () => {
    renderColorPicker();

    const aInput = screen.getByLabelText('Alpha value');
    expect(aInput).toHaveValue(0.56);
  });
});
