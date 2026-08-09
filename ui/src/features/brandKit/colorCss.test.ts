import { describe, expect, it } from 'vitest';

import { buildColorStyles } from '@/features/brandKit/colorCss';

import type { BrandKitColor } from '@/types/CodeComponent';

describe('buildColorStyles', () => {
  it('returns empty string when colors is null', () => {
    expect(buildColorStyles(null)).toBe('');
  });

  it('returns empty string when colors is undefined', () => {
    expect(buildColorStyles(undefined)).toBe('');
  });

  it('returns empty string when colors array is empty', () => {
    expect(buildColorStyles([])).toBe('');
  });

  it('generates CSS for a single sRGB color without alpha', () => {
    const colors: BrandKitColor[] = [
      {
        id: '1',
        name: 'Primary',
        cssVariable: '--color-primary',
        value: {
          colorSpace: 'srgb',
          components: [1.0, 0.341, 0.2],
          alpha: null,
          hex: '#FF5733',
        },
        weight: 0,
      },
    ];

    const result = buildColorStyles(colors);
    expect(result).toBe(`:root {\n  --color-primary: #FF5733;\n}`);
  });

  it('generates CSS for a single sRGB color with alpha 1.0 (uses hex)', () => {
    const colors: BrandKitColor[] = [
      {
        id: '1',
        name: 'Primary',
        cssVariable: '--color-primary',
        value: {
          colorSpace: 'srgb',
          components: [1.0, 0.341, 0.2],
          alpha: 1.0,
          hex: '#FF5733',
        },
        weight: 0,
      },
    ];

    const result = buildColorStyles(colors);
    expect(result).toBe(`:root {\n  --color-primary: #FF5733;\n}`);
  });

  it('generates CSS with rgba for sRGB colors with alpha', () => {
    const colors: BrandKitColor[] = [
      {
        id: '1',
        name: 'Primary',
        cssVariable: '--color-primary',
        value: {
          colorSpace: 'srgb',
          components: [1.0, 0.341, 0.2],
          alpha: 0.5,
          hex: '#FF5733',
        },
        weight: 0,
      },
    ];

    const result = buildColorStyles(colors);
    expect(result).toBe(
      `:root {\n  --color-primary: rgba(255, 87, 51, 0.5);\n}`,
    );
  });

  it('generates CSS for HSL colors', () => {
    const colors: BrandKitColor[] = [
      {
        id: '1',
        name: 'Primary',
        cssVariable: '--color-primary',
        value: {
          colorSpace: 'hsl',
          components: [120, 100, 50],
          alpha: null,
          hex: null,
        },
        weight: 0,
      },
    ];

    const result = buildColorStyles(colors);
    expect(result).toBe(`:root {\n  --color-primary: hsl(120, 100%, 50%);\n}`);
  });

  it('generates CSS with hsla for HSL colors with alpha', () => {
    const colors: BrandKitColor[] = [
      {
        id: '1',
        name: 'Primary',
        cssVariable: '--color-primary',
        value: {
          colorSpace: 'hsl',
          components: [240, 100, 50],
          alpha: 0.5,
          hex: null,
        },
        weight: 0,
      },
    ];

    const result = buildColorStyles(colors);
    expect(result).toBe(
      `:root {\n  --color-primary: hsla(240, 100%, 50%, 0.5);\n}`,
    );
  });

  it('sorts colors by weight', () => {
    const colors: BrandKitColor[] = [
      {
        id: '2',
        name: 'Secondary',
        cssVariable: '--color-secondary',
        value: {
          colorSpace: 'srgb',
          components: [0.2, 1.0, 0.341],
          alpha: null,
          hex: '#33FF57',
        },
        weight: 10,
      },
      {
        id: '1',
        name: 'Primary',
        cssVariable: '--color-primary',
        value: {
          colorSpace: 'srgb',
          components: [1.0, 0.341, 0.2],
          alpha: null,
          hex: '#FF5733',
        },
        weight: 0,
      },
    ];

    const result = buildColorStyles(colors);
    const lines = result.split('\n');
    expect(lines[1]).toContain('--color-primary');
    expect(lines[2]).toContain('--color-secondary');
  });

  it('sorts colors alphabetically by name when weights are equal', () => {
    const colors: BrandKitColor[] = [
      {
        id: '2',
        name: 'Zebra',
        cssVariable: '--color-zebra',
        value: {
          colorSpace: 'srgb',
          components: [0.0, 0.0, 0.0],
          alpha: null,
          hex: '#000000',
        },
        weight: 0,
      },
      {
        id: '1',
        name: 'Alpha',
        cssVariable: '--color-alpha',
        value: {
          colorSpace: 'srgb',
          components: [1.0, 1.0, 1.0],
          alpha: null,
          hex: '#FFFFFF',
        },
        weight: 0,
      },
    ];

    const result = buildColorStyles(colors);
    const lines = result.split('\n');
    expect(lines[1]).toContain('--color-alpha');
    expect(lines[2]).toContain('--color-zebra');
  });

  it('handles multiple colors with mixed alpha values', () => {
    const colors: BrandKitColor[] = [
      {
        id: '1',
        name: 'Solid',
        cssVariable: '--color-solid',
        value: {
          colorSpace: 'srgb',
          components: [0.0, 0.0, 0.0],
          alpha: null,
          hex: '#000000',
        },
        weight: 0,
      },
      {
        id: '2',
        name: 'Transparent',
        cssVariable: '--color-transparent',
        value: {
          colorSpace: 'srgb',
          components: [1.0, 1.0, 1.0],
          alpha: 0.75,
          hex: '#FFFFFF',
        },
        weight: 1,
      },
    ];

    const result = buildColorStyles(colors);
    expect(result).toContain('--color-solid: #000000');
    expect(result).toContain('--color-transparent: rgba(255, 255, 255, 0.75)');
  });

  it('handles HSL and sRGB colors together', () => {
    const colors: BrandKitColor[] = [
      {
        id: '1',
        name: 'SRGB Color',
        cssVariable: '--color-srgb',
        value: {
          colorSpace: 'srgb',
          components: [1.0, 0.0, 0.0],
          alpha: null,
          hex: '#FF0000',
        },
        weight: 0,
      },
      {
        id: '2',
        name: 'HSL Color',
        cssVariable: '--color-hsl',
        value: {
          colorSpace: 'hsl',
          components: [120, 100, 50],
          alpha: null,
          hex: null,
        },
        weight: 1,
      },
    ];

    const result = buildColorStyles(colors);
    expect(result).toContain('--color-srgb: #FF0000');
    expect(result).toContain('--color-hsl: hsl(120, 100%, 50%)');
  });
});
