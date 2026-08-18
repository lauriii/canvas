import type { AssetLibraryFont } from '@/types/CodeComponent';

const fontFormatLabels: Record<AssetLibraryFont['format'], string> = {
  woff2: 'woff2',
  woff: 'woff',
  ttf: 'truetype',
  otf: 'opentype',
};

const fontMimeTypes: Record<AssetLibraryFont['format'], string> = {
  woff2: 'font/woff2',
  woff: 'font/woff',
  ttf: 'font/ttf',
  otf: 'font/otf',
};

type PersistedAssetLibraryFont = Pick<
  AssetLibraryFont,
  'id' | 'family' | 'uri' | 'format'
> & {
  weight: string;
  style: string;
  axes?: AssetLibraryFont['axes'];
};

export const isVariableFont = (font: AssetLibraryFont): boolean =>
  font.variantType === 'variable' || (font.axes?.length ?? 0) > 0;

export const stripFontClientFields = (
  font: AssetLibraryFont,
): PersistedAssetLibraryFont => {
  const persistedFont: PersistedAssetLibraryFont = {
    id: font.id,
    family: font.family,
    uri: font.uri,
    format: font.format,
    weight: getWeightDeclaration(font),
    style: getFontFaceStyleDeclaration(font),
  };

  if (font.axes?.length) {
    persistedFont.axes = font.axes;
  }

  return persistedFont;
};

export const stripFontListClientFields = (
  fonts: AssetLibraryFont[],
): PersistedAssetLibraryFont[] => fonts.map(stripFontClientFields);

export const groupFontsByFamily = (
  fonts: AssetLibraryFont[],
): Array<{ family: string; fonts: AssetLibraryFont[] }> => {
  const groupedFonts = new Map<string, AssetLibraryFont[]>();

  fonts.forEach((font) => {
    const family = font.family.trim() || 'New font';
    const familyFonts = groupedFonts.get(family) ?? [];
    familyFonts.push(font);
    groupedFonts.set(family, familyFonts);
  });

  return Array.from(groupedFonts.entries())
    .map(([family, familyFonts]) => ({
      family,
      fonts: familyFonts,
    }))
    .sort((left, right) => left.family.localeCompare(right.family));
};

const getFontFormatLabel = (font: AssetLibraryFont): string =>
  font.format.toUpperCase();

export const buildFontVariantName = (font: AssetLibraryFont): string =>
  isVariableFont(font)
    ? 'Variable'
    : `${font.weight} ${font.style === 'italic' ? 'Italic' : 'Normal'}`;

export const buildFontVariantLabel = (font: AssetLibraryFont): string =>
  `${buildFontVariantName(font)} [${getFontFormatLabel(font)}]`;

/**
 * A family is variable when every file uploaded under it is a variable font.
 */
export const isVariableFontFamily = (fonts: AssetLibraryFont[]): boolean =>
  fonts.length > 0 && fonts.every((font) => isVariableFont(font));

/**
 * Lists the distinct file formats a family was uploaded in, in upload order.
 */
export const buildFontFamilyFormatsLabel = (
  fonts: AssetLibraryFont[],
): string =>
  Array.from(new Set(fonts.map((font) => getFontFormatLabel(font)))).join(
    ' / ',
  );

/**
 * Counts a family's files the way the family is meant to be read: variable
 * families ship whole ranges rather than individual weights.
 */
export const buildFontFamilySummary = (fonts: AssetLibraryFont[]): string => {
  if (isVariableFontFamily(fonts)) {
    return `${fonts.length} variable ${fonts.length === 1 ? 'font' : 'fonts'}`;
  }

  return `${fonts.length} ${fonts.length === 1 ? 'variant' : 'variants'}`;
};

const formatAxisValue = (value: number): string =>
  Number.isInteger(value) ? String(value) : String(Number(value.toFixed(2)));

const getAxisSettingValue = (
  font: AssetLibraryFont,
  tag: string,
): number | null =>
  font.axisSettings?.find((axis) => axis.tag === tag)?.value ??
  font.axes?.find((axis) => axis.tag === tag)?.default ??
  null;

const getWeightDeclaration = (font: AssetLibraryFont): string => {
  const weightAxis = font.axes?.find((axis) => axis.tag === 'wght');
  if (isVariableFont(font) && weightAxis) {
    return `${formatAxisValue(weightAxis.min)} ${formatAxisValue(weightAxis.max)}`;
  }

  return font.weight;
};

/**
 * The `font-style` a single `@font-face` can honestly declare for this file.
 *
 * A range is only spellable for `oblique <angle>`: `normal italic` is not a
 * valid descriptor, so a font whose `ital` axis spans both is declared as the
 * upright face it defaults to. Its italic is reached through
 * `font-variation-settings`, not by asking the browser for `font-style:
 * italic` — which, against an upright face, would have the browser fake a slant
 * on top of the one the axis already applies.
 */
export const getFontFaceStyleDeclaration = (font: AssetLibraryFont): string => {
  const italicAxis = font.axes?.find((axis) => axis.tag === 'ital');
  if (isVariableFont(font) && italicAxis) {
    return italicAxis.default > 0 ? 'italic' : 'normal';
  }

  const slantAxis = font.axes?.find((axis) => axis.tag === 'slnt');
  if (isVariableFont(font) && slantAxis) {
    return slantAxis.default !== 0 ? 'italic' : 'normal';
  }

  return font.style;
};

const buildFontTokenName = (fontFamily: string): string =>
  fontFamily
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '') || 'custom-font';

const escapeCssString = (value: string, quote: "'" | '"'): string =>
  value
    .replaceAll('\\', '\\\\')
    .replaceAll(quote, `\\${quote}`)
    .replaceAll('</style>', '<\\/style>');

export const buildFontFaceSnippet = (font: AssetLibraryFont): string => {
  const fontFamily = escapeCssString(font.family, "'");
  const fontUrl = escapeCssString(font.url ?? font.uri, "'");
  const lines = [
    '@font-face {',
    `  font-family: '${fontFamily}';`,
    `  src: url('${fontUrl}') format('${fontFormatLabels[font.format]}');`,
    `  font-weight: ${getWeightDeclaration(font)};`,
    `  font-style: ${getFontFaceStyleDeclaration(font)};`,
    '  font-display: swap;',
    '}',
  ];

  return lines.join('\n');
};

export const buildTailwindThemeSnippet = (font: AssetLibraryFont): string => {
  const tokenName = buildFontTokenName(font.family);
  const fontFamily = escapeCssString(font.family, '"');
  return [
    '@theme {',
    `  --font-${tokenName}: "${fontFamily}", sans-serif;`,
    '}',
  ].join('\n');
};

export const buildFontFaceStyles = (
  fonts: AssetLibraryFont[] | null | undefined,
): string =>
  (fonts ?? []).map((font) => buildFontFaceSnippet(font)).join('\n\n');

export const getFontPreloadDefinitions = (
  fonts: AssetLibraryFont[] | null | undefined,
): Array<{ href: string; type: string }> => {
  const definitions = new Map<string, { href: string; type: string }>();

  for (const font of fonts ?? []) {
    const href = font.url ?? null;
    if (!href) {
      continue;
    }

    definitions.set(href, {
      href,
      type: fontMimeTypes[font.format],
    });
  }

  return Array.from(definitions.values());
};

/**
 * The declarations an author actually has to write to reproduce what the panel
 * is previewing.
 *
 * The family comes from the Tailwind theme token, and anything already sitting
 * at its initial value is left out: weight 400, upright, and any axis resting
 * on its own default all render that way with nothing declared. A snippet that
 * spells them out reads as though they were doing something.
 *
 * `font-weight` is the high-level property for `wght`, so that axis is not
 * repeated in `font-variation-settings`; the axes with no high-level property —
 * optical size, width, slant — are what that declaration is for.
 *
 * `font-style` is whatever the accompanying `@font-face` declares, never what
 * the slant axis currently reads: asking for an italic the face does not have
 * makes the browser fake one on top of the slant the axis already applies.
 */
const buildUsageDeclarations = (font: AssetLibraryFont): string[] => {
  const declarations: string[] = [];
  const isVariable = isVariableFont(font) && !!font.axes?.length;

  const weightAxisValue = isVariable ? getAxisSettingValue(font, 'wght') : null;
  const weight =
    weightAxisValue !== null
      ? formatAxisValue(weightAxisValue)
      : isVariable
        ? null
        : font.weight.trim();
  if (weight && weight !== '400' && weight !== 'normal') {
    declarations.push(`font-weight: ${weight}`);
  }

  const style = isVariable ? getFontFaceStyleDeclaration(font) : font.style;
  if (style !== 'normal') {
    declarations.push(`font-style: ${style}`);
  }

  if (isVariable) {
    const variations = (font.axes ?? [])
      .filter((axis) => axis.tag !== 'wght')
      .map((axis) => ({
        tag: axis.tag,
        value: getAxisSettingValue(font, axis.tag) ?? axis.default,
        fallback: axis.default,
      }))
      .filter((axis) => axis.value !== axis.fallback)
      .map((axis) => `'${axis.tag}' ${formatAxisValue(axis.value)}`);

    if (variations.length > 0) {
      declarations.push(`font-variation-settings: ${variations.join(', ')}`);
    }
  }

  return declarations;
};

export const buildTailwindHtmlSnippet = (font: AssetLibraryFont): string => {
  const tokenName = buildFontTokenName(font.family);
  // The weight of a static font is free text an author typed, and this goes
  // into a double-quoted attribute they will paste into their own markup.
  const inlineStyle = buildUsageDeclarations(font)
    .map((declaration) => `${declaration};`)
    .join(' ')
    .replaceAll('"', '&quot;');
  // A font already at its defaults needs the class and nothing else.
  const styleAttribute = inlineStyle ? ` style="${inlineStyle}"` : '';

  return [
    `<p class="font-${tokenName}"${styleAttribute}>`,
    '  The quick brown fox jumps over the lazy dog.',
    '</p>',
  ].join('\n');
};

export const buildFontSnippet = (font: AssetLibraryFont): string => {
  return `${buildTailwindThemeSnippet(font)}\n\n${buildTailwindHtmlSnippet(font)}`;
};
