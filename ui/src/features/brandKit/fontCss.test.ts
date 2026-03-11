import { describe, expect, it } from 'vitest';

import {
  buildFontFaceSnippet,
  buildFontFaceStyles,
  buildFontSnippet,
  buildFontVariantLabel,
  buildTailwindHtmlSnippet,
  buildTailwindThemeSnippet,
  getFontPreloadDefinitions,
  groupFontsByFamily,
  stripFontClientFields,
  stripFontListClientFields,
} from '@/features/brandKit/fontCss';

const FONT_ASSET_BASE_URI = 'public://canvas/assets/';
const FONT_ASSET_BASE_URL = '/sites/default/files/canvas/assets/';

describe('fontCss helpers', () => {
  const font = {
    id: 'inter-400-normal',
    family: 'Inter',
    uri: `${FONT_ASSET_BASE_URI}inter.woff2`,
    url: `${FONT_ASSET_BASE_URL}inter.woff2`,
    format: 'woff2' as const,
    variantType: 'static' as const,
    weight: '400',
    style: 'normal' as const,
    axes: null,
    axisSettings: null,
  };

  const variableFont = {
    id: 'inter-variable',
    family: 'Inter',
    uri: `${FONT_ASSET_BASE_URI}inter-variable.woff2`,
    url: `${FONT_ASSET_BASE_URL}inter-variable.woff2`,
    format: 'woff2' as const,
    variantType: 'variable' as const,
    weight: '400',
    style: 'normal' as const,
    axes: [
      {
        tag: 'wght',
        name: 'Weight',
        min: 100,
        max: 900,
        default: 400,
      },
      {
        tag: 'wdth',
        name: 'Width',
        min: 75,
        max: 125,
        default: 100,
      },
    ],
    axisSettings: [
      {
        tag: 'wght',
        value: 450,
      },
      {
        tag: 'wdth',
        value: 100,
      },
    ],
  };

  const slantedVariableFont = {
    ...variableFont,
    id: 'recursive-variable',
    family: 'Recursive',
    weight: '400',
    style: 'italic' as const,
    axes: [
      {
        tag: 'wght',
        name: 'Weight',
        min: 300,
        max: 1000,
        default: 400,
      },
      {
        tag: 'slnt',
        name: 'Slant',
        min: -15,
        max: 0,
        default: -15,
      },
    ],
    axisSettings: [
      {
        tag: 'wght',
        value: 450,
      },
      {
        tag: 'slnt',
        value: -15,
      },
    ],
  };

  it('builds a copyable @font-face snippet', () => {
    expect(buildFontFaceSnippet(font)).toBe(`@font-face {
  font-family: 'Inter';
  src: url('${FONT_ASSET_BASE_URL}inter.woff2') format('woff2');
  font-weight: 400;
  font-style: normal;
}`);
  });

  it('builds shared font-face styles and preload definitions', () => {
    expect(buildFontFaceStyles([font, variableFont])).toContain('@font-face {');
    expect(getFontPreloadDefinitions([font, variableFont])).toEqual([
      {
        href: `${FONT_ASSET_BASE_URL}inter.woff2`,
        type: 'font/woff2',
      },
      {
        href: `${FONT_ASSET_BASE_URL}inter-variable.woff2`,
        type: 'font/woff2',
      },
    ]);
  });

  it('strips client-only fields before persisting fonts', () => {
    expect(stripFontClientFields(font)).toEqual({
      id: 'inter-400-normal',
      family: 'Inter',
      uri: `${FONT_ASSET_BASE_URI}inter.woff2`,
      format: 'woff2',
      weight: '400',
      style: 'normal',
    });
    expect(stripFontListClientFields([font])).toEqual([
      {
        id: 'inter-400-normal',
        family: 'Inter',
        uri: `${FONT_ASSET_BASE_URI}inter.woff2`,
        format: 'woff2',
        weight: '400',
        style: 'normal',
      },
    ]);
  });

  it('groups fonts by family for the family list view', () => {
    expect(
      groupFontsByFamily([
        font,
        {
          ...font,
          id: 'inter-700-italic',
          weight: '700',
          style: 'italic',
        },
        {
          ...font,
          id: 'mona-400-normal',
          family: 'Mona Sans',
        },
      ]),
    ).toEqual([
      {
        family: 'Inter',
        fonts: [
          font,
          {
            ...font,
            id: 'inter-700-italic',
            weight: '700',
            style: 'italic',
          },
        ],
      },
      {
        family: 'Mona Sans',
        fonts: [
          {
            ...font,
            id: 'mona-400-normal',
            family: 'Mona Sans',
          },
        ],
      },
    ]);
  });

  it('builds a readable variant label', () => {
    expect(buildFontVariantLabel(font)).toBe('400 Normal · WOFF2');
  });

  it('escapes unsafe characters in generated CSS snippets', () => {
    expect(
      buildFontFaceSnippet({
        ...font,
        family: `Mona's "Sans"</style>`,
        url: `${FONT_ASSET_BASE_URL}mona's-font.woff2</style>`,
      }),
    ).toBe(`@font-face {
  font-family: 'Mona\\'s "Sans"<\\/style>';
  src: url('${FONT_ASSET_BASE_URL}mona\\'s-font.woff2<\\/style>') format('woff2');
  font-weight: 400;
  font-style: normal;
}`);

    expect(
      buildTailwindThemeSnippet({
        ...font,
        family: `Mona "Sans"\\Display`,
      }),
    ).toBe(`@theme {
  --font-mona-sans-display: "Mona \\"Sans\\"\\\\Display", sans-serif;
}`);
  });

  it('builds variable font activation and usage snippets', () => {
    expect(buildFontFaceSnippet(variableFont)).toBe(`@font-face {
  font-family: 'Inter';
  src: url('${FONT_ASSET_BASE_URL}inter-variable.woff2') format('woff2');
  font-weight: 100 900;
  font-style: normal;
}`);

    expect(buildTailwindThemeSnippet(font)).toBe(`@theme {
  --font-inter: "Inter", sans-serif;
}`);
    expect(buildTailwindHtmlSnippet(variableFont))
      .toBe(`<p class="font-inter font-[450] not-italic [font-variation-settings:'wght'_450,'wdth'_100]">
  The quick brown fox jumps over the lazy dog.
</p>`);

    expect(buildFontSnippet(font)).toBe(`@theme {
  --font-inter: "Inter", sans-serif;
}

<p class="font-inter font-[400] not-italic">
  The quick brown fox jumps over the lazy dog.
</p>`);
    expect(buildFontSnippet(variableFont)).toBe(`@theme {
  --font-inter: "Inter", sans-serif;
}

<p class="font-inter font-[450] not-italic [font-variation-settings:'wght'_450,'wdth'_100]">
  The quick brown fox jumps over the lazy dog.
</p>`);
    expect(buildFontVariantLabel(variableFont)).toBe('Variable · WOFF2');
  });

  it('treats slnt variable fonts as italic', () => {
    expect(buildFontFaceSnippet(slantedVariableFont)).toBe(`@font-face {
  font-family: 'Recursive';
  src: url('${FONT_ASSET_BASE_URL}inter-variable.woff2') format('woff2');
  font-weight: 300 1000;
  font-style: italic;
}`);

    expect(buildTailwindHtmlSnippet(slantedVariableFont))
      .toBe(`<p class="font-recursive font-[450] italic [font-variation-settings:'wght'_450,'slnt'_-15]">
  The quick brown fox jumps over the lazy dog.
</p>`);
  });
});
