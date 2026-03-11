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
    style: getStyleDeclaration(font),
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

export const buildFontVariantLabel = (font: AssetLibraryFont): string =>
  isVariableFont(font)
    ? `Variable · ${font.format.toUpperCase()}`
    : `${font.weight} ${font.style === 'italic' ? 'Italic' : 'Normal'} · ${font.format.toUpperCase()}`;

const formatAxisValue = (value: number): string =>
  Number.isInteger(value) ? String(value) : String(Number(value.toFixed(2)));

const getAxisSettingValue = (
  font: AssetLibraryFont,
  tag: string,
): number | null =>
  font.axisSettings?.find((axis) => axis.tag === tag)?.value ??
  font.axes?.find((axis) => axis.tag === tag)?.default ??
  null;

const getVariableItalicState = (
  font: AssetLibraryFont,
): 'italic' | 'normal' | null => {
  const italicValue = getAxisSettingValue(font, 'ital');
  if (italicValue !== null) {
    return italicValue > 0 ? 'italic' : 'normal';
  }

  const slantValue = getAxisSettingValue(font, 'slnt');
  if (slantValue !== null) {
    return slantValue !== 0 ? 'italic' : 'normal';
  }

  return null;
};

const getWeightDeclaration = (font: AssetLibraryFont): string => {
  const weightAxis = font.axes?.find((axis) => axis.tag === 'wght');
  if (isVariableFont(font) && weightAxis) {
    return `${formatAxisValue(weightAxis.min)} ${formatAxisValue(weightAxis.max)}`;
  }

  return font.weight;
};

const getStyleDeclaration = (font: AssetLibraryFont): string => {
  const italicAxis = font.axes?.find((axis) => axis.tag === 'ital');
  if (isVariableFont(font) && italicAxis) {
    if (italicAxis.min <= 0 && italicAxis.max >= 1) {
      return 'normal italic';
    }

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
    `  font-style: ${getStyleDeclaration(font)};`,
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

export const buildTailwindHtmlSnippet = (font: AssetLibraryFont): string => {
  const tokenName = buildFontTokenName(font.family);
  const styleClassParts = [`font-${tokenName}`];

  if (isVariableFont(font) && font.axes?.length) {
    const axisSettings = font.axes.map((axis) => ({
      tag: axis.tag,
      value: getAxisSettingValue(font, axis.tag) ?? axis.default,
    }));

    const weightValue = getAxisSettingValue(font, 'wght');
    if (weightValue !== null) {
      styleClassParts.push(`font-[${formatAxisValue(weightValue)}]`);
    }

    const italicState = getVariableItalicState(font);
    if (italicState !== null) {
      styleClassParts.push(italicState === 'italic' ? 'italic' : 'not-italic');
    } else {
      styleClassParts.push(font.style === 'italic' ? 'italic' : 'not-italic');
    }

    styleClassParts.push(
      `[font-variation-settings:${axisSettings
        .map((axis) => `'${axis.tag}'_${formatAxisValue(axis.value)}`)
        .join(',')}]`,
    );
  } else {
    styleClassParts.push(
      `font-[${font.weight}]`,
      font.style === 'italic' ? 'italic' : 'not-italic',
    );
  }

  return [
    `<p class="${styleClassParts.join(' ')}">`,
    '  The quick brown fox jumps over the lazy dog.',
    '</p>',
  ].join('\n');
};

export const buildFontSnippet = (font: AssetLibraryFont): string => {
  return `${buildTailwindThemeSnippet(font)}\n\n${buildTailwindHtmlSnippet(font)}`;
};
