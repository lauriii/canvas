import { describe, expect, it } from 'vitest';

import { validateColorsConfig } from './color-validate';

import type { BrandKitColorsFileMap } from '@drupal-canvas/discovery';

function errorsFor(colors: BrandKitColorsFileMap): string {
  try {
    validateColorsConfig(colors);
  } catch (err) {
    return err instanceof Error ? err.message : String(err);
  }
  return '';
}

describe('validateColorsConfig', () => {
  it('accepts valid entries in every form', () => {
    expect(() =>
      validateColorsConfig({
        'brand-red': '#cc0000',
        '--prefixed': '#00cc00',
        overlay: 'hsla(220, 60%, 50%, 0.5)',
        'brand-rgb': 'rgb(204, 0, 0)',
        'alpha-hex': '#cc000080',
        exact: { colorSpace: 'srgb', components: [0.1, 0.2, 0.3] },
        accent: { value: '#0000cc', name: 'Accent blue', displayFormat: 'rgb' },
      }),
    ).not.toThrow();
  });

  it('accepts an empty palette', () => {
    expect(() => validateColorsConfig({})).not.toThrow();
  });

  it('rejects a non-object colors value', () => {
    for (const bad of [[], null, 'red', 42]) {
      const message = errorsFor(bad as unknown as BrandKitColorsFileMap);
      expect(message).toContain('must be an object');
      expect(message).toContain('"brand-red": "#cc0000"');
    }
  });

  it('rejects a malformed color string naming the entry and the accepted forms', () => {
    const message = errorsFor({ 'brand-red': '#cc00' });
    expect(message).toContain('Color "brand-red"');
    expect(message).toContain('#cc00');
    expect(message).toContain('"#cc0000"');
    expect(message).toContain('rgb(204, 0, 0)');
  });

  it('rejects an invalid color key', () => {
    const message = errorsFor({ '1bad': '#cc0000' });
    expect(message).toContain('Color "1bad"');
    expect(message).toContain('invalid color key');
  });

  it('rejects two keys naming the same variable', () => {
    const message = errorsFor({
      'brand-red': '#cc0000',
      '--brand-red': '#0000cc',
    });
    expect(message).toContain('duplicate of "brand-red"');
    expect(message).toContain('--brand-red');
  });

  it('rejects an unknown color space', () => {
    const message = errorsFor({
      bad: { colorSpace: 'lab' as 'srgb', components: [0, 0, 0] },
    });
    expect(message).toContain('invalid "colorSpace"');
  });

  it('rejects a wrong component count', () => {
    const message = errorsFor({
      bad: { colorSpace: 'srgb', components: [0, 0] },
    });
    expect(message).toContain('exactly 3 numbers');
  });

  it('rejects an out-of-range or non-finite alpha', () => {
    for (const alpha of [2, Number.NaN, Number.POSITIVE_INFINITY]) {
      const message = errorsFor({
        bad: { colorSpace: 'srgb', components: [0, 0, 0], alpha },
      });
      expect(message).toContain('between 0 and 1');
    }
  });

  it('rejects an invalid stored hex on a token object', () => {
    const message = errorsFor({
      bad: { colorSpace: 'srgb', components: [0, 0, 0], hex: '#cc000080' },
    });
    expect(message).toContain('6-digit hex');
  });

  it('rejects an invalid displayFormat and an empty name on the wrapper form', () => {
    const message = errorsFor({
      bad: { value: '#cc0000', displayFormat: 'cmyk' as 'hex', name: ' ' },
    });
    expect(message).toContain('invalid "displayFormat"');
    expect(message).toContain('rgb, hex, hsl');
    expect(message).toContain('non-empty string');
  });

  it('rejects a missing wrapper value and wrong-typed values', () => {
    expect(errorsFor({ bad: { value: null } as never })).toContain(
      'missing value',
    );
    expect(errorsFor({ bad: 42 as unknown as string })).toContain(
      'invalid value 42',
    );
  });

  it('rejects two colors sharing a name, trimmed and case-insensitively', () => {
    const message = errorsFor({
      'primary-a': { value: '#cc0000', name: 'Primary' },
      'primary-b': { value: '#0000cc', name: ' PRIMARY ' },
    });
    expect(message).toContain('duplicate name " PRIMARY "');
    expect(message).toContain('"primary-a"');
  });

  it('rejects a separator-only key that derives no display name', () => {
    const message = errorsFor({ _: '#cc0000' });
    expect(message).toContain('no display name can be derived');
    expect(message).toContain('"name"');
    // A wrapper name fixes it.
    expect(errorsFor({ _: { value: '#cc0000', name: 'Underscore' } })).toBe('');
  });

  it('rejects an explicit name colliding with a derived one', () => {
    const message = errorsFor({
      'brand-red': '#cc0000',
      accent: { value: '#0000cc', name: 'Brand Red' },
    });
    expect(message).toContain('duplicate name "Brand Red"');
  });

  it('rejects out-of-range token components', () => {
    expect(
      errorsFor({ bad: { colorSpace: 'srgb', components: [1.5, 0, 0] } }),
    ).toContain('sRGB components must be between 0 and 1');
    expect(
      errorsFor({ bad: { colorSpace: 'hsl', components: [220, 150, 50] } }),
    ).toContain('HSL saturation and lightness must be between 0 and 100');
    // Hue is an unbounded angle.
    expect(
      errorsFor({ ok: { colorSpace: 'hsl', components: [-20, 60, 50] } }),
    ).toBe('');
  });

  it('reports every error in one run', () => {
    const message = errorsFor({
      first: '#nope',
      '2bad': '#cc0000',
    });
    expect(message).toContain('Color "first"');
    expect(message).toContain('Color "2bad"');
  });
});
