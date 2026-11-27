import { describe, expect, it } from 'vitest';

import {
  buildTokenStyles,
  convertDimension,
  cssVariableSnippet,
  draftEffectiveValue,
  hasUploadedWeight,
  hexToColorValue,
  isValidFontFamily,
  isValidFontWeight,
  parseNumberInput,
  parseTokenReference,
  tokenValueToCss,
} from '@/features/brandKit/themeTokens';

import type { BrandKitFont, BrandKitThemeToken } from '@/types/CodeComponent';

const token = (
  overrides: Partial<BrandKitThemeToken> = {},
): BrandKitThemeToken => ({
  path: 'color.semantic.primary',
  reference: '{color.semantic.primary}',
  cssVariable: '--color-semantic-primary',
  name: 'Primary',
  description: null,
  provider: { type: 'theme', name: 'olivero', label: 'Olivero' },
  groupPath: ['color', 'semantic'],
  editable: true,
  overridden: false,
  resettable: false,
  reason: null,
  type: 'color',
  value: hexToColorValue('#0055aa'),
  declaredValue: hexToColorValue('#0055aa'),
  draft: null,
  ...overrides,
});

describe('tokenValueToCss', () => {
  it("serializes every type like core's toCss()", () => {
    expect(tokenValueToCss('color', hexToColorValue('#ABC'))).toBe('#aabbcc');
    expect(tokenValueToCss('fontFamily', ['Inter', 'sans-serif'])).toBe(
      'Inter, sans-serif',
    );
    expect(tokenValueToCss('dimension', { value: 1.5, unit: 'rem' })).toBe(
      '1.5rem',
    );
    expect(tokenValueToCss('fontWeight', 700)).toBe('700');
    expect(tokenValueToCss('number', 1.2)).toBe('1.2');
    // The `font` shorthand: letter spacing is not output.
    expect(
      tokenValueToCss('typography', {
        fontFamily: ['Inter', 'sans-serif'],
        fontSize: { value: 32, unit: 'px' },
        fontWeight: 700,
        letterSpacing: { value: -0.5, unit: 'px' },
        lineHeight: 1.2,
      }),
    ).toBe('700 32px/1.2 Inter, sans-serif');
    expect(tokenValueToCss('shadow', {})).toBeNull();
    // A malformed value another tool stored never throws.
    expect(tokenValueToCss('typography', { fontWeight: 700 })).toBeNull();
  });
});

describe('draftEffectiveValue', () => {
  it('prefers a draft, and shows the declared value for a staged reset', () => {
    const red = hexToColorValue('#ff0000');
    const green = hexToColorValue('#00ff00');
    expect(
      draftEffectiveValue(token({ overridden: true, value: red })),
    ).toEqual(red);
    expect(draftEffectiveValue(token({ draft: { value: green } }))).toEqual(
      green,
    );
    expect(
      draftEffectiveValue(
        token({ overridden: true, value: red, draft: { value: null } }),
      ),
    ).toEqual(hexToColorValue('#0055aa'));
  });
});

describe('buildTokenStyles', () => {
  it('emits only overridden or drafted tokens over theme CSS', () => {
    expect(
      buildTokenStyles([token({ path: 'a.b', cssVariable: '--a-b' })], {
        includeDeclared: false,
      }),
    ).toBe('');
    expect(
      buildTokenStyles(
        [token({ draft: { value: hexToColorValue('#ff0000') } })],
        { includeDeclared: false },
      ),
    ).toBe(':root {\n  --color-semantic-primary: #ff0000;\n}');
  });

  it('keeps emitting a path until the next load', () => {
    // The previous test emitted color.semantic.primary: its draft is gone,
    // so the declared value now overrides any stale staged value.
    expect(buildTokenStyles([token()], { includeDeclared: false })).toBe(
      ':root {\n  --color-semantic-primary: #0055aa;\n}',
    );
  });

  it('emits every known token for previews without theme CSS', () => {
    expect(
      buildTokenStyles(
        [
          token({ path: 'x.y', cssVariable: '--x-y' }),
          token({ type: 'shadow', path: 'x.z', cssVariable: '--x-z' }),
        ],
        { includeDeclared: true },
      ),
    ).toBe(':root {\n  --x-y: #0055aa;\n}');
  });
});

describe('helpers', () => {
  it('validates font family names as Canvas writes them', () => {
    expect(isValidFontFamily('-apple-system')).toBe(true);
    expect(isValidFontFamily('Noto Sans JP')).toBe(true);
    expect(isValidFontFamily('Font 3')).toBe(false);
    expect(isValidFontFamily('x;}body{display:none')).toBe(false);
  });

  it('parses references and builds snippets', () => {
    expect(parseTokenReference('{color.semantic.primary}')).toBe(
      'color.semantic.primary',
    );
    expect(parseTokenReference('canvas-color:abc')).toBeNull();
    expect(cssVariableSnippet(token({ type: 'typography' }))).toBe(
      'font: var(--color-semantic-primary)',
    );
  });
});

describe('editor values', () => {
  it('parses number fields, keeping invalid input out', () => {
    expect(parseNumberInput('1.5')).toBe(1.5);
    expect(parseNumberInput('-0.25')).toBe(-0.25);
    expect(parseNumberInput('.5')).toBe(0.5);
    expect(parseNumberInput('1.123456')).toBe(1.1235);
    expect(parseNumberInput('1..5')).toBeNull();
    expect(parseNumberInput('')).toBeNull();
    expect(parseNumberInput('1e400')).toBeNull();
  });

  it('converts dimensions at 16px per rem, to 3 decimals', () => {
    expect(convertDimension({ value: 16, unit: 'px' }, 'rem')).toEqual({
      value: 1,
      unit: 'rem',
    });
    expect(convertDimension({ value: 1.5, unit: 'rem' }, 'px')).toEqual({
      value: 24,
      unit: 'px',
    });
    expect(convertDimension({ value: 10, unit: 'px' }, 'rem')).toEqual({
      value: 0.625,
      unit: 'rem',
    });
    expect(convertDimension({ value: 7, unit: 'px' }, 'rem').value).toBe(0.438);
  });

  it('accepts whole font weights from 1 to 1000', () => {
    expect(isValidFontWeight(700)).toBe(true);
    expect(isValidFontWeight(0)).toBe(false);
    expect(isValidFontWeight(1001)).toBe(false);
    expect(isValidFontWeight(400.5)).toBe(false);
  });

  it('flags weights an uploaded family has no file for', () => {
    const fonts = [
      { family: 'Inter', weight: '400' },
      { family: 'Inter', weight: 'bold' },
      { family: 'Recursive', weight: '300 1000' },
    ] as BrandKitFont[];
    expect(hasUploadedWeight(fonts, 'Inter', 400)).toBe(true);
    expect(hasUploadedWeight(fonts, 'Inter', 700)).toBe(true);
    expect(hasUploadedWeight(fonts, 'Inter', 600)).toBe(false);
    expect(hasUploadedWeight(fonts, 'Recursive', 850)).toBe(true);
    // Not an uploaded family: nothing to flag.
    expect(hasUploadedWeight(fonts, 'Georgia', 600)).toBeNull();
  });
});
