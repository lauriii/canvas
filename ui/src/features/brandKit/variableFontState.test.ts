import { beforeEach, describe, expect, it } from 'vitest';

import {
  getFontVariationSettings,
  hydrateFontForUi,
  readStoredAxisSelections,
  syncFontDefaultsFromAxisSettings,
  writeStoredAxisSelections,
} from '@/features/brandKit/variableFontState';

import type { AssetLibraryFont } from '@/types/CodeComponent';

const variableFont: AssetLibraryFont = {
  id: 'font-1',
  family: 'Plus Jakarta Sans',
  uri: '/fonts/plus-jakarta-sans.woff2',
  format: 'woff2',
  variantType: 'variable',
  weight: '400',
  style: 'normal',
  axes: [
    {
      tag: 'wght',
      min: 200,
      max: 800,
      default: 400,
      name: 'Weight',
    },
    {
      tag: 'slnt',
      min: -10,
      max: 0,
      default: 0,
      name: 'Slant',
    },
  ],
  axisSettings: null,
};

describe('variableFontState', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('hydrates variable fonts from stored axis selections', () => {
    writeStoredAxisSelections({
      'font-1': [
        { tag: 'wght', value: 650 },
        { tag: 'slnt', value: -8 },
      ],
    });

    const hydratedFont = hydrateFontForUi(
      variableFont,
      readStoredAxisSelections(),
    );

    expect(hydratedFont.axisSettings).toEqual([
      { tag: 'wght', value: 650 },
      { tag: 'slnt', value: -8 },
    ]);
    expect(hydratedFont.weight).toBe('650');
    expect(hydratedFont.style).toBe('italic');
  });

  it('derives italic style from slant axis values', () => {
    const updatedFont = syncFontDefaultsFromAxisSettings(variableFont, [
      { tag: 'wght', value: 500 },
      { tag: 'slnt', value: -6 },
    ]);

    expect(updatedFont.weight).toBe('500');
    expect(updatedFont.style).toBe('italic');
  });

  it('builds font-variation-settings from current axis settings', () => {
    const updatedFont = syncFontDefaultsFromAxisSettings(variableFont, [
      { tag: 'wght', value: 500 },
      { tag: 'slnt', value: -6 },
    ]);

    expect(getFontVariationSettings(updatedFont)).toBe('"wght" 500, "slnt" -6');
  });
});
