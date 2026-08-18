import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ColorInputs from './ColorInputs';

import type { HsvaColor } from '@uiw/color-convert';

import styles from './ColorPicker.module.css';

const TEST_HSVA: HsvaColor = { h: 210, s: 50, v: 100, a: 1 };

const renderColorInputs = (
  props: {
    hsva?: HsvaColor;
    onChange?: (hsva: HsvaColor) => void;
    mode?: 'rgba' | 'hsla' | 'hex';
    onModeChange?: (mode: 'rgba' | 'hsla' | 'hex') => void;
    onValidityChange?: (isValid: boolean) => void;
  } = {},
) => {
  const onChange = vi.fn();
  render(
    <ColorInputs
      hsva={props.hsva ?? TEST_HSVA}
      onChange={props.onChange ?? onChange}
      mode={props.mode ?? 'rgba'}
      onModeChange={props.onModeChange ?? vi.fn()}
      onValidityChange={props.onValidityChange}
    />,
  );
  return { onChange };
};

describe('ColorInputs', () => {
  it('allows clearing an RGB value and typing a new one', async () => {
    const user = userEvent.setup();
    const { onChange } = renderColorInputs();

    const rInput = screen.getByLabelText('Red value');
    expect(rInput).toHaveValue(128);

    await user.clear(rInput);
    await user.type(rInput, '25');

    expect(rInput).toHaveValue(25);
    expect(onChange).toHaveBeenCalled();
  });

  it('allows clearing an HSL value and typing a new one', async () => {
    const user = userEvent.setup();
    const { onChange } = renderColorInputs({ mode: 'hsla' });

    const hInput = screen.getByLabelText('Hue value');
    expect(hInput).toHaveValue(210);

    await user.clear(hInput);
    await user.type(hInput, '120');

    expect(hInput).toHaveValue(120);
    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({ h: 120, s: 50, v: 100, a: 1 }),
    );
  });

  it('resets to the current value when a numeric input is blurred with invalid input', async () => {
    const user = userEvent.setup();
    renderColorInputs();

    const rInput = screen.getByLabelText('Red value');
    await user.clear(rInput);
    await user.type(rInput, '999');

    expect(rInput).toHaveValue(999);

    await user.tab();

    expect(rInput).toHaveValue(128);
  });

  it('shows a red outline for empty or out-of-range values while focused', async () => {
    const user = userEvent.setup();
    renderColorInputs();

    const rInput = screen.getByLabelText('Red value');
    expect(rInput).not.toHaveClass(styles.rgbaNativeInputInvalidSubtle);

    await user.clear(rInput);
    expect(rInput).toHaveClass(styles.rgbaNativeInputInvalidSubtle);

    await user.type(rInput, '999');
    expect(rInput).toHaveClass(styles.rgbaNativeInputInvalidSubtle);

    await user.clear(rInput);
    await user.type(rInput, '128');
    expect(rInput).not.toHaveClass(styles.rgbaNativeInputInvalidSubtle);
  });

  it('reports invalid state via onValidityChange when a numeric value is out of range', async () => {
    const user = userEvent.setup();
    const onValidityChange = vi.fn();
    renderColorInputs({ onValidityChange });

    const rInput = screen.getByLabelText('Red value');
    await user.clear(rInput);
    await user.type(rInput, '999');

    expect(onValidityChange).toHaveBeenLastCalledWith(false);

    await user.clear(rInput);
    await user.type(rInput, '128');

    expect(onValidityChange).toHaveBeenLastCalledWith(true);
  });

  it('rounds alpha values to two decimal places', async () => {
    const user = userEvent.setup();
    const { onChange } = renderColorInputs();

    const aInput = screen.getByLabelText('Alpha value');
    await user.clear(aInput);
    await user.type(aInput, '0.555');

    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({ a: 0.56 }),
    );
  });
});
