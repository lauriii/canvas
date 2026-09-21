import { describe, expect, it } from 'vitest';

import {
  buildFontFaceSnippet,
  buildFontFaceStyles,
  buildFontFamilyFormatsLabel,
  buildFontFamilySummary,
  buildFontSnippet,
  buildFontVariantLabel,
  buildTailwindHtmlSnippet,
  buildTailwindThemeSnippet,
  getFontPreloadDefinitions,
  groupFontsByFamily,
  isVariableFontFamily,
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
  font-display: swap;
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
    expect(buildFontVariantLabel(font)).toBe('400 Normal [WOFF2]');
    expect(
      buildFontVariantLabel({ ...font, style: 'italic', weight: '700' }),
    ).toBe('700 Italic [WOFF2]');
  });

  it('describes a family by what it ships', () => {
    expect(isVariableFontFamily([variableFont])).toBe(true);
    expect(isVariableFontFamily([variableFont, font])).toBe(false);
    expect(isVariableFontFamily([])).toBe(false);

    expect(buildFontFamilySummary([variableFont])).toBe('1 variable font');
    expect(buildFontFamilySummary([font])).toBe('1 variant');
    expect(buildFontFamilySummary([font, { ...font, id: 'inter-700' }])).toBe(
      '2 variants',
    );

    expect(buildFontFamilyFormatsLabel([font, variableFont])).toBe('WOFF2');
    expect(
      buildFontFamilyFormatsLabel([font, { ...font, format: 'ttf' }]),
    ).toBe('WOFF2 / TTF');
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
  font-display: swap;
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

  it('leaves out utilities that are already the default', () => {
    // Nothing to say: the token carries the family, and 400 upright is what a
    // paragraph renders as anyway.
    expect(buildTailwindHtmlSnippet(font)).toContain('<p class="font-inter">');

    // Weights on Tailwind's own scale get its name; the rest are arbitrary.
    expect(buildTailwindHtmlSnippet({ ...font, weight: '300' })).toContain(
      '<p class="font-inter font-light">',
    );
    expect(buildTailwindHtmlSnippet({ ...font, weight: '250' })).toContain(
      '<p class="font-inter font-[250]">',
    );
    expect(
      buildTailwindHtmlSnippet({ ...font, weight: '700', style: 'italic' }),
    ).toContain('<p class="font-inter font-bold italic">');
  });

  it('builds variable font activation and usage snippets', () => {
    expect(buildFontFaceSnippet(variableFont)).toBe(`@font-face {
  font-family: 'Inter';
  src: url('${FONT_ASSET_BASE_URL}inter-variable.woff2') format('woff2');
  font-weight: 100 900;
  font-style: normal;
  font-display: swap;
}`);

    expect(buildTailwindThemeSnippet(font)).toBe(`@theme {
  --font-inter: "Inter", sans-serif;
}`);
    // `wdth` sits on its own default and upright is the initial style, so
    // neither needs a utility; only the weight is doing anything.
    expect(buildTailwindHtmlSnippet(variableFont))
      .toBe(`<p class="font-inter font-[450]">
  The quick brown fox jumps over the lazy dog.
</p>`);

    // A 400 upright font renders that way with nothing declared at all.
    expect(buildFontSnippet(font)).toBe(`@theme {
  --font-inter: "Inter", sans-serif;
}

<p class="font-inter">
  The quick brown fox jumps over the lazy dog.
</p>`);
    expect(buildFontSnippet(variableFont)).toBe(`@theme {
  --font-inter: "Inter", sans-serif;
}

<p class="font-inter font-[450]">
  The quick brown fox jumps over the lazy dog.
</p>`);
    expect(buildFontVariantLabel(variableFont)).toBe('Variable [WOFF2]');
  });

  it('never declares a font-style range on an @font-face', () => {
    // `normal italic` is not a valid `font-style` descriptor — a range is only
    // spellable for `oblique <angle>` — so a font whose ital axis covers both
    // is declared as the upright face it defaults to.
    const dualItalicFont = {
      ...variableFont,
      axes: [
        { tag: 'wght', name: 'Weight', min: 100, max: 900, default: 400 },
        { tag: 'ital', name: 'Italic', min: 0, max: 1, default: 0 },
      ],
      axisSettings: [
        { tag: 'wght', value: 400 },
        { tag: 'ital', value: 1 },
      ],
    };

    expect(buildFontFaceSnippet(dualItalicFont)).toContain(
      'font-style: normal;',
    );
    expect(buildFontFaceSnippet(dualItalicFont)).not.toContain('normal italic');

    // The usage snippet must not ask for an italic that face does not have:
    // the browser would fake a slant on top of the one 'ital' applies.
    expect(buildTailwindHtmlSnippet(dualItalicFont)).toContain(
      '<p class="font-inter [font-variation-settings:\'ital\'_1]">',
    );
    expect(buildTailwindHtmlSnippet(dualItalicFont)).not.toContain('italic]');
  });

  it('escapes a typed weight before putting it in an attribute', () => {
    expect(
      buildTailwindHtmlSnippet({ ...font, weight: '400" onload="x' }),
    ).toContain('font-[400&quot;_onload=&quot;x]');
  });

  it('treats slnt variable fonts as italic', () => {
    expect(buildFontFaceSnippet(slantedVariableFont)).toBe(`@font-face {
  font-family: 'Recursive';
  src: url('${FONT_ASSET_BASE_URL}inter-variable.woff2') format('woff2');
  font-weight: 300 1000;
  font-style: italic;
  font-display: swap;
}`);

    // The slant axis is at its own default, so the face's `italic` says it all.
    expect(buildTailwindHtmlSnippet(slantedVariableFont))
      .toBe(`<p class="font-recursive font-[450] italic">
  The quick brown fox jumps over the lazy dog.
</p>`);
  });
});
