import { BRAND_KIT_ACCEPTED_FILE_TYPES } from '@/features/brandKit/constants';

import { parseFontFile } from './fontParser';

import type { LocalizedName } from 'opentype.js';
import type {
  AssetLibraryFont,
  AssetLibraryFontAxis,
  AssetLibraryFontAxisSetting,
} from '@/types/CodeComponent';
import type { ParsedFont } from './fontParser';

type ParsedVariationAxis = NonNullable<
  NonNullable<ParsedFont['tables']['fvar']>['axes']
>[number];

const normalizeFontFamily = (
  value: string | null | undefined,
): string | null => {
  const normalized = value?.trim().replace(/\s+/g, ' ');
  return normalized ? normalized : null;
};

const normalizeAxisNumber = (value: number): number =>
  Number.isInteger(value) ? value : Number.parseFloat(value.toFixed(2));

const normalizeAxisName = (value: string | null | undefined): string | null => {
  const normalized = value?.trim().replace(/\s+/g, ' ');
  return normalized ? normalized : null;
};

const getLocalizedNameValue = (
  value: string | LocalizedName | null | undefined,
): string | null => {
  if (typeof value === 'string') {
    return normalizeFontFamily(value);
  }

  if (!value) {
    return null;
  }

  return (
    normalizeFontFamily(value.en) ??
    normalizeFontFamily(value['en-US']) ??
    Object.values(value)
      .map((entry) => normalizeFontFamily(entry))
      .find((entry): entry is string => entry !== null) ??
    null
  );
};

export const fallbackFontFamilyFromFilename = (filename: string): string => {
  const basename = filename.replace(/\.[^.]+$/, '');
  const words = basename
    .replace(/[_-]+/g, ' ')
    .trim()
    .split(/\s+/)
    .filter(Boolean);

  if (words.length === 0) {
    return 'New font';
  }

  return words
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};

const getFileFormat = (file: File): AssetLibraryFont['format'] | null => {
  const extension = file.name.split('.').pop()?.toLowerCase();
  if (
    extension &&
    BRAND_KIT_ACCEPTED_FILE_TYPES.includes(
      extension as AssetLibraryFont['format'],
    )
  ) {
    return extension as AssetLibraryFont['format'];
  }

  return null;
};

const toAssetLibraryAxis = (
  axis: ParsedVariationAxis,
): AssetLibraryFontAxis => ({
  tag: axis.tag,
  name: normalizeAxisName(getLocalizedNameValue(axis.name)) ?? undefined,
  min: normalizeAxisNumber(axis.minValue),
  max: normalizeAxisNumber(axis.maxValue),
  default: normalizeAxisNumber(axis.defaultValue),
});

const toAxisSettings = (
  axes: AssetLibraryFontAxis[],
): AssetLibraryFontAxisSetting[] =>
  axes.map((axis) => ({
    tag: axis.tag,
    value: axis.default,
  }));

const getAxisValue = (
  axisSettings: AssetLibraryFontAxisSetting[],
  tag: string,
): number | null =>
  axisSettings.find((axis) => axis.tag === tag)?.value ?? null;

const getFontStyleFromAxes = (
  font: ParsedFont,
  filename: string,
  axisSettings: AssetLibraryFontAxisSetting[],
): AssetLibraryFont['style'] => {
  const italicAxisValue = getAxisValue(axisSettings, 'ital');
  if (italicAxisValue !== null) {
    return italicAxisValue > 0 ? 'italic' : 'normal';
  }

  const slantAxisValue = getAxisValue(axisSettings, 'slnt');
  if (slantAxisValue !== null) {
    return slantAxisValue !== 0 ? 'italic' : 'normal';
  }

  return getFontStyleFromMetadata(font, filename);
};

const normalizeWeightClass = (
  value: number | null | undefined,
): string | null => {
  if (typeof value !== 'number' || Number.isNaN(value) || value <= 0) {
    return null;
  }

  return String(Math.round(value));
};

const getWeightNameMatch = (names: Array<string | null>): string | null => {
  const normalizedNames = names.filter((name): name is string => name !== null);

  const weightNameMap: Array<[RegExp, string]> = [
    [/\b(thin|hairline)\b/i, '100'],
    [/\b(extra[\s-]?light|ultra[\s-]?light)\b/i, '200'],
    [/\blight\b/i, '300'],
    [/\b(normal|regular|book|roman)\b/i, '400'],
    [/\bmedium\b/i, '500'],
    [/\b(semi[\s-]?bold|demi[\s-]?bold)\b/i, '600'],
    [/\bbold\b/i, '700'],
    [/\b(extra[\s-]?bold|ultra[\s-]?bold)\b/i, '800'],
    [/\b(black|heavy|extra[\s-]?black|ultra[\s-]?black)\b/i, '900'],
  ];

  for (const [pattern, weight] of weightNameMap) {
    if (normalizedNames.some((name) => pattern.test(name))) {
      return weight;
    }
  }

  return null;
};

const getFontWeightFromMetadata = (font: ParsedFont): string => {
  const os2WeightClass = normalizeWeightClass(font.tables.os2?.usWeightClass);
  if (os2WeightClass !== null) {
    return os2WeightClass;
  }

  const names = font.names as Partial<
    Record<'preferredSubfamily' | 'fontSubfamily' | 'fullName', LocalizedName>
  >;
  const weightName = getWeightNameMatch([
    getLocalizedNameValue(names.preferredSubfamily),
    getLocalizedNameValue(names.fontSubfamily),
    getLocalizedNameValue(names.fullName),
  ]);

  return weightName ?? '400';
};

const getFontWeightFromAxes = (
  font: ParsedFont,
  axisSettings: AssetLibraryFontAxisSetting[],
) => {
  const weightAxisValue = getAxisValue(axisSettings, 'wght');
  return weightAxisValue === null
    ? getFontWeightFromMetadata(font)
    : String(weightAxisValue);
};

export interface ReadFontMetadataResult {
  family: string | null;
  variantType: AssetLibraryFont['variantType'];
  axes: AssetLibraryFontAxis[] | null;
  axisSettings: AssetLibraryFontAxisSetting[] | null;
  weight: string;
  style: AssetLibraryFont['style'];
}

const getFontFamilyFromMetadata = (font: ParsedFont): string | null => {
  const names = font.names as Partial<
    Record<'preferredFamily' | 'fontFamily' | 'fullName', LocalizedName>
  >;

  return (
    getLocalizedNameValue(names.preferredFamily) ??
    getLocalizedNameValue(names.fontFamily) ??
    getLocalizedNameValue(names.fullName) ??
    null
  );
};

const isItalicStyleName = (value: string | null | undefined): boolean =>
  /\b(italic|oblique|slanted)\b/i.test(value ?? '');

const getFontStyleFromMetadata = (
  font: ParsedFont,
  _filename?: string,
): AssetLibraryFont['style'] => {
  const names = font.names as Partial<
    Record<'preferredSubfamily' | 'fontSubfamily' | 'fullName', LocalizedName>
  >;
  const styleNames = [
    getLocalizedNameValue(names.preferredSubfamily),
    getLocalizedNameValue(names.fontSubfamily),
    getLocalizedNameValue(names.fullName),
  ];
  if (styleNames.some((name) => isItalicStyleName(name))) {
    return 'italic';
  }

  const os2 = font.tables.os2;
  if ((os2?.fsSelection ?? 0) & 0x01) {
    return 'italic';
  }

  const head = font.tables.head;
  if (((head?.macStyle ?? 0) & 0x02) !== 0) {
    return 'italic';
  }

  const post = font.tables.post;
  if ((post?.italicAngle ?? 0) !== 0) {
    return 'italic';
  }

  return 'normal';
};

export const readFontMetadataFromFile = async (
  file: File,
): Promise<ReadFontMetadataResult | null> => {
  const format = getFileFormat(file);
  if (!format) {
    return null;
  }

  try {
    const font = await parseFontFile(file, format);

    const axes =
      font.tables.fvar?.axes?.map((axis) => toAssetLibraryAxis(axis)) ?? [];
    const axisSettings = axes.length > 0 ? toAxisSettings(axes) : null;

    return {
      family: getFontFamilyFromMetadata(font),
      variantType: axes.length > 0 ? 'variable' : 'static',
      axes: axes.length > 0 ? axes : null,
      axisSettings,
      weight: axisSettings
        ? getFontWeightFromAxes(font, axisSettings)
        : getFontWeightFromMetadata(font),
      style: axisSettings
        ? getFontStyleFromAxes(font, file.name, axisSettings)
        : getFontStyleFromMetadata(font, file.name),
    };
  } catch (error) {
    console.warn('Failed to read font metadata from uploaded file.', error);
    return null;
  }
};

export const readFontFamilyFromFile = async (
  file: File,
): Promise<string | null> => {
  return (await readFontMetadataFromFile(file))?.family ?? null;
};
