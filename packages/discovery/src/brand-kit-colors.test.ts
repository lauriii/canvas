import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import {
  BRAND_KIT_CONFIG_FILENAME,
  buildBrandKitColorCss,
  colorTokenToCss,
  colorTokenValuesEqual,
  deriveColorName,
  normalizeBrandKitColors,
  normalizeColorKey,
  normalizeColorValue,
  parseCssColorString,
  parseHexColor,
  readBrandKitColors,
  serializeColorValue,
} from './brand-kit-colors';

import type { ColorTokenValue } from './brand-kit-colors';

async function makeTempDir(): Promise<string> {
  return fs.mkdtemp(path.join(os.tmpdir(), 'canvas-brand-kit-colors-'));
}

const tempDirs: string[] = [];

afterEach(async () => {
  await Promise.all(
    tempDirs.map((dir) => fs.rm(dir, { recursive: true, force: true })),
  );
  tempDirs.length = 0;
});

describe('normalizeColorKey', () => {
  it('accepts bare keys and strips a -- prefix', () => {
    expect(normalizeColorKey('brand-red')).toBe('brand-red');
    expect(normalizeColorKey('--brand-red')).toBe('brand-red');
    expect(normalizeColorKey('_private')).toBe('_private');
  });

  it('rejects keys that cannot name a custom property', () => {
    for (const bad of ['', '1red', 'brand red', 'brand.red', '--', '--1x']) {
      expect(normalizeColorKey(bad)).toBeNull();
    }
  });
});

describe('deriveColorName', () => {
  it('derives a title-cased name from the key', () => {
    expect(deriveColorName('brand-red')).toBe('Brand Red');
    expect(deriveColorName('overlay')).toBe('Overlay');
    expect(deriveColorName('text_muted')).toBe('Text Muted');
  });
});

describe('parseHexColor', () => {
  it('parses a six-digit hex into srgb components with a null alpha', () => {
    expect(parseHexColor('#cc0000')).toEqual({
      colorSpace: 'srgb',
      components: [204 / 255, 0, 0],
      alpha: null,
      hex: '#cc0000',
    });
  });

  it('parses an eight-digit hex preserving the exact alpha', () => {
    const token = parseHexColor('#cc000080');
    expect(token).toMatchObject({ colorSpace: 'srgb', hex: '#cc0000' });
    expect(token?.alpha).toBe(128 / 255);
    // Rounding would collapse a 1/255 alpha to 0.
    expect(parseHexColor('#cc000001')?.alpha).toBe(1 / 255);
  });

  it('treats a ff alpha channel as opaque', () => {
    expect(parseHexColor('#cc0000ff')?.alpha).toBeNull();
  });

  it('rejects malformed strings', () => {
    for (const bad of ['#cc00', 'cc0000', '#cc000', '#gg0000', '#cc00001']) {
      expect(parseHexColor(bad)).toBeNull();
    }
  });
});

describe('parseCssColorString', () => {
  it('parses rgb() and rgba() in comma and space syntax', () => {
    expect(parseCssColorString('rgb(204, 0, 0)')).toEqual({
      token: {
        colorSpace: 'srgb',
        components: [204 / 255, 0, 0],
        alpha: null,
        hex: '#cc0000',
      },
      displayFormat: 'rgb',
    });
    expect(parseCssColorString('rgba(204 0 0 / 0.5)')?.token.alpha).toBe(0.5);
    expect(parseCssColorString('rgba(204, 0, 0, 50%)')?.token.alpha).toBe(0.5);
  });

  it('parses hsl() and hsla() without computing a hex', () => {
    expect(parseCssColorString('hsl(220, 60%, 50%)')).toEqual({
      token: {
        colorSpace: 'hsl',
        components: [220, 60, 50],
        alpha: null,
        hex: null,
      },
      displayFormat: 'hsl',
    });
    expect(parseCssColorString('hsla(220, 60%, 50%, 0.5)')?.token.alpha).toBe(
      0.5,
    );
    expect(parseCssColorString('hsl(220 60% 50% / 0.5)')?.token.alpha).toBe(
      0.5,
    );
  });

  it('rejects out-of-range channels, alphas, percentages, and junk', () => {
    for (const bad of [
      'rgb(300, 0, 0)',
      'rgb(1, 2)',
      'rgba(204, 0, 0, 2)',
      'rgba(204, 0, 0, 150%)',
      'hsl(220, 60, 50)',
      'hsl(220, 160%, 50%)',
      'hsla(220, 60%, 50%, 1.5)',
      'rgba(204, 0, 0, 0.5.6)',
      'hsl(220.4.1, 60%, 50%)',
      'red',
      'var(--other)',
    ]) {
      expect(parseCssColorString(bad)).toBeNull();
    }
  });
});

describe('colorTokenValuesEqual', () => {
  const red: ColorTokenValue = {
    colorSpace: 'srgb',
    components: [0.8, 0, 0],
    alpha: null,
    hex: '#cc0000',
  };

  it('treats absent, null, and 1 alpha as equal', () => {
    expect(
      colorTokenValuesEqual(red, { ...red, alpha: undefined }),
    ).toBeTruthy();
    expect(colorTokenValuesEqual(red, { ...red, alpha: 1 })).toBeTruthy();
    expect(colorTokenValuesEqual(red, { ...red, alpha: 0.5 })).toBeFalsy();
  });

  it('compares hex case-insensitively and ignores a one-sided hex', () => {
    expect(colorTokenValuesEqual(red, { ...red, hex: '#CC0000' })).toBeTruthy();
    expect(colorTokenValuesEqual(red, { ...red, hex: null })).toBeTruthy();
  });

  it('distinguishes color spaces and components', () => {
    expect(
      colorTokenValuesEqual(red, { ...red, colorSpace: 'hsl' }),
    ).toBeFalsy();
    expect(
      colorTokenValuesEqual(red, { ...red, components: [0.8, 0.1, 0] }),
    ).toBeFalsy();
  });
});

describe('serializeColorValue', () => {
  it('writes an opaque srgb color with an exact stored hex as the string', () => {
    expect(
      serializeColorValue({
        colorSpace: 'srgb',
        components: [204 / 255, 0, 0],
        alpha: null,
        hex: '#cc0000',
      }),
    ).toBe('#cc0000');
  });

  it('writes translucent srgb as rgba() when exact', () => {
    expect(
      serializeColorValue({
        colorSpace: 'srgb',
        components: [204 / 255, 0, 0],
        alpha: 0.5,
        hex: '#cc0000',
      }),
    ).toBe('rgba(204, 0, 0, 0.5)');
  });

  it('writes hsl tokens as hsl()/hsla() strings', () => {
    expect(
      serializeColorValue({
        colorSpace: 'hsl',
        components: [220, 60, 50],
        alpha: null,
        hex: '#3366cc',
      }),
    ).toBe('hsl(220, 60%, 50%)');
    expect(
      serializeColorValue({
        colorSpace: 'hsl',
        components: [220.5, 60, 50],
        alpha: 0.25,
        hex: null,
      }),
    ).toBe('hsla(220.5, 60%, 50%, 0.25)');
  });

  it('keeps a token object when components only round to the hex', () => {
    // 0.1 rounds to hex 1a, but 0x1a / 255 is not 0.1 — a string form
    // would change the components on the next push.
    expect(
      serializeColorValue({
        colorSpace: 'srgb',
        components: [0.1, 0.2, 0.3],
        alpha: null,
        hex: '#1a334d',
      }),
    ).toEqual({
      colorSpace: 'srgb',
      components: [0.1, 0.2, 0.3],
      hex: '#1a334d',
    });
  });

  it('keeps a token object when the stored hex disagrees with the components', () => {
    expect(
      serializeColorValue({
        colorSpace: 'srgb',
        components: [0.5, 0.5, 0.5],
        alpha: null,
        hex: '#000000',
      }),
    ).toEqual({
      colorSpace: 'srgb',
      components: [0.5, 0.5, 0.5],
      hex: '#000000',
    });
  });

  it('keeps the rgb() form for opaque colors displayed as RGB', () => {
    expect(
      serializeColorValue(
        {
          colorSpace: 'srgb',
          components: [20 / 255, 24 / 255, 31 / 255],
          alpha: null,
          hex: '#14181f',
        },
        'rgb',
      ),
    ).toBe('rgb(20, 24, 31)');
    // Without the preference the same token serializes as hex.
    expect(
      serializeColorValue({
        colorSpace: 'srgb',
        components: [20 / 255, 24 / 255, 31 / 255],
        alpha: null,
        hex: '#14181f',
      }),
    ).toBe('#14181f');
  });

  it('round-trips through the parsers', () => {
    for (const value of [
      '#1a2b3c',
      'rgba(204, 0, 0, 0.5)',
      'hsl(220, 60%, 50%)',
      'hsla(220, 60%, 50%, 0.13)',
    ]) {
      const parsed = parseCssColorString(value);
      expect(serializeColorValue(parsed!.token)).toBe(value);
    }
  });
});

describe('colorTokenToCss', () => {
  it('prefers the stored hex for opaque srgb', () => {
    expect(
      colorTokenToCss({
        colorSpace: 'srgb',
        components: [0.8, 0, 0],
        hex: '#CC0000',
      }),
    ).toBe('#CC0000');
  });

  it('computes hex from components when none is stored', () => {
    expect(
      colorTokenToCss({ colorSpace: 'srgb', components: [0.8, 0, 0] }),
    ).toBe('#cc0000');
  });

  it('clamps out-of-range components instead of emitting invalid hex', () => {
    expect(
      colorTokenToCss({ colorSpace: 'srgb', components: [1.5, -0.2, 0] }),
    ).toBe('#ff0000');
  });

  it('renders translucent srgb as rgba', () => {
    expect(
      colorTokenToCss({
        colorSpace: 'srgb',
        components: [0.8, 0, 0],
        alpha: 0.5,
        hex: '#cc0000',
      }),
    ).toBe('rgba(204, 0, 0, 0.5)');
  });

  it('ignores a malformed stored hex and computes from components', () => {
    expect(
      colorTokenToCss({ colorSpace: 'srgb', components: [0.8, 0, 0], hex: '' }),
    ).toBe('#cc0000');
    expect(
      colorTokenToCss({
        colorSpace: 'srgb',
        components: [0.8, 0, 0],
        alpha: 0.5,
        hex: '#f00',
      }),
    ).toBe('rgba(204, 0, 0, 0.5)');
  });

  it('renders hsl and hsla with rounded components', () => {
    expect(
      colorTokenToCss({ colorSpace: 'hsl', components: [220.4, 59.6, 50] }),
    ).toBe('hsl(220, 60%, 50%)');
    expect(
      colorTokenToCss({
        colorSpace: 'hsl',
        components: [220, 60, 50],
        alpha: 0.25,
      }),
    ).toBe('hsla(220, 60%, 50%, 0.25)');
  });
});

describe('normalizeColorValue', () => {
  it('returns null for missing or malformed values', () => {
    expect(normalizeColorValue(undefined)).toBeNull();
    expect(normalizeColorValue(null)).toBeNull();
    expect(normalizeColorValue('#nope')).toBeNull();
    expect(normalizeColorValue({ colorSpace: 'srgb' } as never)).toBeNull();
    expect(normalizeColorValue([] as never)).toBeNull();
    expect(normalizeColorValue(42 as never)).toBeNull();
    // Malformed token shapes must not reach the CSS renderer.
    expect(
      normalizeColorValue({ colorSpace: 'hsl', components: [] } as never),
    ).toBeNull();
    expect(
      normalizeColorValue({
        colorSpace: 'srgb',
        components: ['1', 0, 0],
      } as never),
    ).toBeNull();
    expect(
      normalizeColorValue({
        colorSpace: 'lab',
        components: [0, 0, 0],
      } as never),
    ).toBeNull();
    expect(
      normalizeColorValue({
        colorSpace: 'srgb',
        components: [0, 0, Number.NaN],
      } as never),
    ).toBeNull();
    expect(
      normalizeColorValue({
        colorSpace: 'srgb',
        components: [0, 0, 0],
        alpha: 2,
      } as never),
    ).toBeNull();
  });

  it('passes through a well-formed token object', () => {
    const token = { colorSpace: 'srgb' as const, components: [0.8, 0, 0] };
    expect(normalizeColorValue(token)).toBe(token);
  });
});

describe('normalizeBrandKitColors', () => {
  it('normalizes plain string entries with derived names', () => {
    const colors = normalizeBrandKitColors({ 'brand-red': '#cc0000' });
    expect(colors).toHaveLength(1);
    expect(colors[0]).toMatchObject({
      rawKey: 'brand-red',
      key: 'brand-red',
      cssVariable: '--brand-red',
      name: 'Brand Red',
      explicitName: undefined,
      derivedDisplayFormat: 'hex',
    });
    expect(colors[0].token).toMatchObject({ hex: '#cc0000' });
  });

  it('normalizes prefixed keys and wrapper objects', () => {
    const colors = normalizeBrandKitColors({
      '--accent': { value: '#00cc00', name: 'Accent green' },
    });
    expect(colors[0]).toMatchObject({
      rawKey: '--accent',
      key: 'accent',
      cssVariable: '--accent',
      name: 'Accent green',
      explicitName: 'Accent green',
    });
  });

  it('accepts a bare token object as the map value', () => {
    const colors = normalizeBrandKitColors({
      exact: { colorSpace: 'srgb', components: [0.1, 0.2, 0.3] },
    });
    expect(colors[0].token).toEqual({
      colorSpace: 'srgb',
      components: [0.1, 0.2, 0.3],
    });
  });

  it('skips invalid keys and yields null tokens for junk values', () => {
    const colors = normalizeBrandKitColors({
      '1bad': '#cc0000',
      broken: 'not-a-color',
    });
    expect(colors).toHaveLength(1);
    expect(colors[0].key).toBe('broken');
    expect(colors[0].token).toBeNull();
  });

  it('returns an empty array for non-object input', () => {
    expect(normalizeBrandKitColors(undefined)).toEqual([]);
    expect(normalizeBrandKitColors([])).toEqual([]);
    expect(normalizeBrandKitColors('nope')).toEqual([]);
  });
});

describe('buildBrandKitColorCss', () => {
  it('emits a :root block in map order', () => {
    const css = buildBrandKitColorCss(
      normalizeBrandKitColors({
        'brand-red': '#cc0000',
        overlay: 'hsla(220, 60%, 50%, 0.5)',
      }),
    );
    expect(css).toBe(
      ':root {\n  --brand-red: #cc0000;\n  --overlay: hsla(220, 60%, 50%, 0.5);\n}',
    );
  });

  it('skips entries whose value cannot be parsed', () => {
    const css = buildBrandKitColorCss(
      normalizeBrandKitColors({
        bad: '#xyz',
        good: '#00cc00',
      }),
    );
    expect(css).toBe(':root {\n  --good: #00cc00;\n}');
  });

  it('returns an empty string when nothing renders', () => {
    expect(buildBrandKitColorCss([])).toBe('');
  });
});

describe('readBrandKitColors', () => {
  it('reads and normalizes the colors map from canvas.brand-kit.json', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    await fs.writeFile(
      path.join(root, BRAND_KIT_CONFIG_FILENAME),
      JSON.stringify({
        fonts: { families: [] },
        colors: { 'brand-red': '#cc0000' },
      }),
      'utf-8',
    );
    const colors = readBrandKitColors(root);
    expect(colors).toHaveLength(1);
    expect(colors[0].cssVariable).toBe('--brand-red');
  });

  it('returns an empty array for a missing file, invalid JSON, or a non-object colors key', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    expect(readBrandKitColors(root)).toEqual([]);
    const file = path.join(root, BRAND_KIT_CONFIG_FILENAME);
    await fs.writeFile(file, '{ "colors": {', 'utf-8');
    expect(readBrandKitColors(root)).toEqual([]);
    await fs.writeFile(file, JSON.stringify({ colors: [1, 2] }), 'utf-8');
    expect(readBrandKitColors(root)).toEqual([]);
  });
});
