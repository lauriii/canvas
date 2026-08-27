import { describe, expect, it } from 'vitest';

import { validateColorsConfig } from './color-validate';

import type { BrandKitColorFileEntry } from '@drupal-canvas/discovery';

const valid: BrandKitColorFileEntry = {
  name: 'Brand Red',
  cssVariable: '--brand-red',
  value: '#cc0000',
};

function errorsFor(colors: BrandKitColorFileEntry[]): string {
  try {
    validateColorsConfig(colors);
  } catch (err) {
    return err instanceof Error ? err.message : String(err);
  }
  return '';
}

describe('validateColorsConfig', () => {
  it('accepts valid entries', () => {
    expect(() =>
      validateColorsConfig([
        valid,
        {
          name: 'Overlay',
          cssVariable: '--overlay',
          value: {
            colorSpace: 'hsl',
            components: [220, 60, 50],
            alpha: 0.5,
          },
          displayFormat: 'hsl',
        },
        {
          name: 'Alpha hex',
          cssVariable: '--alpha-hex',
          value: '#cc000080',
        },
      ]),
    ).not.toThrow();
  });

  it('accepts an empty palette', () => {
    expect(() => validateColorsConfig([])).not.toThrow();
  });

  it('rejects a malformed hex naming the entry and the expected format', () => {
    const message = errorsFor([{ ...valid, value: '#cc00' }]);
    expect(message).toContain('Color "Brand Red" (index 0)');
    expect(message).toContain('#cc00');
    expect(message).toContain('#cc0000');
  });

  it('rejects a missing name', () => {
    const message = errorsFor([{ ...valid, name: ' ' }]);
    expect(message).toContain('Color at index 0');
    expect(message).toContain('missing or empty "name"');
  });

  it('rejects a duplicate name pointing at both entries', () => {
    const message = errorsFor([
      valid,
      { ...valid, cssVariable: '--brand-red-2' },
    ]);
    expect(message).toContain('duplicate name "Brand Red"');
    expect(message).toContain('index 0');
  });

  it('rejects an invalid CSS custom property name', () => {
    const message = errorsFor([{ ...valid, cssVariable: 'brand-red' }]);
    expect(message).toContain('invalid "cssVariable": "brand-red"');
    expect(message).toContain('--brand-red');
  });

  it('rejects a duplicate cssVariable', () => {
    const message = errorsFor([valid, { ...valid, name: 'Other' }]);
    expect(message).toContain('duplicate cssVariable "--brand-red"');
  });

  it('rejects an unknown color space', () => {
    const message = errorsFor([
      {
        ...valid,
        value: { colorSpace: 'lab' as 'srgb', components: [0, 0, 0] },
      },
    ]);
    expect(message).toContain('invalid "value.colorSpace"');
  });

  it('rejects a wrong component count', () => {
    const message = errorsFor([
      { ...valid, value: { colorSpace: 'srgb', components: [0, 0] } },
    ]);
    expect(message).toContain('exactly 3 numbers');
  });

  it('rejects an out-of-range alpha', () => {
    const message = errorsFor([
      {
        ...valid,
        value: { colorSpace: 'srgb', components: [0, 0, 0], alpha: 2 },
      },
    ]);
    expect(message).toContain('between 0 and 1');
  });

  it('rejects an invalid stored hex on a token object', () => {
    const message = errorsFor([
      {
        ...valid,
        value: { colorSpace: 'srgb', components: [0, 0, 0], hex: '#cc000080' },
      },
    ]);
    expect(message).toContain('6-digit hex');
  });

  it('rejects an invalid displayFormat', () => {
    const message = errorsFor([{ ...valid, displayFormat: 'cmyk' as 'hex' }]);
    expect(message).toContain('invalid "displayFormat"');
    expect(message).toContain('rgb, hex, hsl');
  });

  it('rejects a missing value', () => {
    const message = errorsFor([
      { name: 'No value', cssVariable: '--no-value' } as BrandKitColorFileEntry,
    ]);
    expect(message).toContain('missing "value"');
  });

  it('reports every error in one run', () => {
    const message = errorsFor([
      { ...valid, value: '#nope' },
      { name: '', cssVariable: '--x', value: '#cc0000' },
    ]);
    expect(message).toContain('index 0');
    expect(message).toContain('index 1');
  });
});
