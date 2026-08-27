import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import {
  BRAND_KIT_CONFIG_FILENAME,
  buildBrandKitColorCss,
  colorTokenToCss,
  colorTokenValuesEqual,
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

describe('parseHexColor', () => {
  it('parses a six-digit hex into srgb components with a null alpha', () => {
    expect(parseHexColor('#cc0000')).toEqual({
      colorSpace: 'srgb',
      components: [204 / 255, 0, 0],
      alpha: null,
      hex: '#cc0000',
    });
  });

  it('parses an eight-digit hex into a two-decimal alpha', () => {
    const token = parseHexColor('#cc000080');
    expect(token).toMatchObject({
      colorSpace: 'srgb',
      hex: '#cc0000',
    });
    expect(token?.alpha).toBe(0.5);
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

  it('compares hex case-insensitively', () => {
    expect(colorTokenValuesEqual(red, { ...red, hex: '#CC0000' })).toBeTruthy();
  });

  it('ignores a one-sided hex', () => {
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
  it('writes an opaque srgb color with a stored hex as the plain string', () => {
    expect(
      serializeColorValue({
        colorSpace: 'srgb',
        components: [0.8, 0, 0],
        alpha: null,
        hex: '#cc0000',
      }),
    ).toBe('#cc0000');
  });

  it('keeps a token object when alpha is meaningful', () => {
    expect(
      serializeColorValue({
        colorSpace: 'srgb',
        components: [0.8, 0, 0],
        alpha: 0.5,
        hex: '#cc0000',
      }),
    ).toEqual({
      colorSpace: 'srgb',
      components: [0.8, 0, 0],
      alpha: 0.5,
      hex: '#cc0000',
    });
  });

  it('keeps a token object when no hex is stored, omitting empty keys', () => {
    expect(
      serializeColorValue({
        colorSpace: 'srgb',
        components: [0.8, 0, 0],
        alpha: null,
        hex: null,
      }),
    ).toEqual({ colorSpace: 'srgb', components: [0.8, 0, 0] });
  });

  it('round-trips through parseHexColor', () => {
    const token = parseHexColor('#1a2b3c');
    expect(serializeColorValue(token!)).toBe('#1a2b3c');
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

describe('buildBrandKitColorCss', () => {
  it('emits a :root block in file order', () => {
    const css = buildBrandKitColorCss([
      { name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' },
      {
        name: 'Overlay',
        cssVariable: '--overlay',
        value: { colorSpace: 'hsl', components: [220, 60, 50], alpha: 0.5 },
      },
    ]);
    expect(css).toBe(
      ':root {\n  --brand-red: #cc0000;\n  --overlay: hsla(220, 60%, 50%, 0.5);\n}',
    );
  });

  it('skips entries with invalid variables or unparseable values', () => {
    const css = buildBrandKitColorCss([
      { name: 'Bad var', cssVariable: 'brand-red', value: '#cc0000' },
      { name: 'Bad value', cssVariable: '--bad', value: '#xyz' },
      { name: 'Good', cssVariable: '--good', value: '#00cc00' },
    ]);
    expect(css).toBe(':root {\n  --good: #00cc00;\n}');
  });

  it('returns an empty string when nothing renders', () => {
    expect(buildBrandKitColorCss([])).toBe('');
  });
});

describe('readBrandKitColors', () => {
  it('reads color entries from canvas.brand-kit.json', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    await fs.writeFile(
      path.join(root, BRAND_KIT_CONFIG_FILENAME),
      JSON.stringify({
        fonts: { families: [] },
        colors: [
          { name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' },
        ],
      }),
      'utf-8',
    );
    expect(readBrandKitColors(root)).toEqual([
      { name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' },
    ]);
  });

  it('returns an empty array for a missing file', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    expect(readBrandKitColors(root)).toEqual([]);
  });

  it('returns an empty array for invalid JSON', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    await fs.writeFile(
      path.join(root, BRAND_KIT_CONFIG_FILENAME),
      '{ "colors": [',
      'utf-8',
    );
    expect(readBrandKitColors(root)).toEqual([]);
  });

  it('returns an empty array when colors is absent or not an array', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    const file = path.join(root, BRAND_KIT_CONFIG_FILENAME);
    await fs.writeFile(file, JSON.stringify({ fonts: {} }), 'utf-8');
    expect(readBrandKitColors(root)).toEqual([]);
    await fs.writeFile(file, JSON.stringify({ colors: {} }), 'utf-8');
    expect(readBrandKitColors(root)).toEqual([]);
  });

  it('drops non-object entries', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    await fs.writeFile(
      path.join(root, BRAND_KIT_CONFIG_FILENAME),
      JSON.stringify({ colors: [null, 'nope', 42, { name: 'ok' }] }),
      'utf-8',
    );
    expect(readBrandKitColors(root)).toEqual([{ name: 'ok' }]);
  });
});
