import { describe, expect, it } from 'vitest';

import { EMPTY_OPTION_VALUE } from '@/components/form/components/selectEmptyOption';
import { resolveOptionsOverrides } from '@/components/form/react-hook-form/fields/componentFieldValidation';

const options = [
  { value: EMPTY_OPTION_VALUE, label: '- None -', selected: false },
  { value: 'small', label: 'Small', selected: false },
  { value: 'large', label: 'Large', selected: false },
];

const inputAndUiData = (resolved: Record<string, any>) => ({
  model: { 'component-uuid': { resolved } },
});

describe('resolveOptionsOverrides', () => {
  it('pre-selects the sentinel when a single-select prop has no value', () => {
    const overrides = resolveOptionsOverrides(
      { options, attributes: {} },
      inputAndUiData({}),
      'component-uuid',
      'size',
    );

    expect(overrides.options).toEqual([
      expect.objectContaining({ value: EMPTY_OPTION_VALUE, selected: true }),
      expect.objectContaining({ value: 'small', selected: false }),
      expect.objectContaining({ value: 'large', selected: false }),
    ]);
  });

  it('leaves a single-select alone when the prop has a value', () => {
    const overrides = resolveOptionsOverrides(
      { options, attributes: {} },
      inputAndUiData({ size: 'small' }),
      'component-uuid',
      'size',
    );

    expect(overrides).toEqual({});
  });

  it('strips the sentinel from a multi-select, where empty means empty array', () => {
    const overrides = resolveOptionsOverrides(
      { options, attributes: { multiple: true } },
      inputAndUiData({}),
      'component-uuid',
      'colors',
    );

    expect(overrides.options).toEqual([
      expect.objectContaining({ value: 'small' }),
      expect.objectContaining({ value: 'large' }),
    ]);
  });

  it('leaves a required prop alone, so Drupal decides its empty option', () => {
    const overrides = resolveOptionsOverrides(
      { options, attributes: { required: true } },
      inputAndUiData({}),
      'component-uuid',
      'size',
    );

    expect(overrides).toEqual({});
  });

  it('is a no-op for selects that carry no sentinel option', () => {
    const overrides = resolveOptionsOverrides(
      { options: options.slice(1), attributes: {} },
      inputAndUiData({}),
      'component-uuid',
      'size',
    );

    expect(overrides).toEqual({});
  });
});
