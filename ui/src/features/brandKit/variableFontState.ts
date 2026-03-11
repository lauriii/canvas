import { isVariableFont } from '@/features/brandKit/fontCss';

import type { CSSProperties } from 'react';
import type {
  AssetLibraryFont,
  AssetLibraryFontAxis,
  AssetLibraryFontAxisSetting,
} from '@/types/CodeComponent';

const AXIS_SELECTIONS_STORAGE_KEY = 'canvas-brand-kit-axis-selections';

export type StoredAxisSelections = Record<
  string,
  AssetLibraryFontAxisSetting[]
>;

export const getDefaultAxisSettings = (
  font: AssetLibraryFont,
): AssetLibraryFontAxisSetting[] | null =>
  font.axes?.map((axis) => ({
    tag: axis.tag,
    value: axis.default,
  })) ?? null;

export const sanitizeAxisSettings = (
  font: AssetLibraryFont,
  axisSettings: AssetLibraryFontAxisSetting[] | null | undefined,
): AssetLibraryFontAxisSetting[] | null => {
  if (!font.axes?.length) {
    return null;
  }

  return font.axes.map((axis) => {
    const storedValue =
      axisSettings?.find((setting) => setting.tag === axis.tag)?.value ??
      axis.default;
    return {
      tag: axis.tag,
      value: Math.min(axis.max, Math.max(axis.min, storedValue)),
    };
  });
};

export const readStoredAxisSelections = (): StoredAxisSelections => {
  if (typeof window === 'undefined') {
    return {};
  }

  try {
    const rawValue = window.localStorage.getItem(AXIS_SELECTIONS_STORAGE_KEY);
    if (!rawValue) {
      return {};
    }

    const parsed = JSON.parse(rawValue) as StoredAxisSelections;
    return typeof parsed === 'object' && parsed !== null ? parsed : {};
  } catch {
    return {};
  }
};

export const writeStoredAxisSelections = (
  value: StoredAxisSelections,
): void => {
  if (typeof window === 'undefined') {
    return;
  }

  window.localStorage.setItem(
    AXIS_SELECTIONS_STORAGE_KEY,
    JSON.stringify(value),
  );
};

export const getAxisSettingValue = (
  font: AssetLibraryFont,
  axis: AssetLibraryFontAxis,
): number =>
  font.axisSettings?.find((setting) => setting.tag === axis.tag)?.value ??
  axis.default;

export const getAxisStep = (axis: AssetLibraryFontAxis): number =>
  Number.isInteger(axis.min) &&
  Number.isInteger(axis.max) &&
  Number.isInteger(axis.default)
    ? 1
    : 0.1;

export const syncFontDefaultsFromAxisSettings = (
  font: AssetLibraryFont,
  axisSettings: AssetLibraryFontAxisSetting[] | null | undefined,
): AssetLibraryFont => {
  if (!isVariableFont(font)) {
    return {
      ...font,
      variantType: 'static',
    };
  }

  const weightAxisSetting = axisSettings?.find((axis) => axis.tag === 'wght');
  const italicAxisSetting = axisSettings?.find((axis) => axis.tag === 'ital');
  const slantAxisSetting = axisSettings?.find((axis) => axis.tag === 'slnt');
  const style =
    italicAxisSetting !== undefined
      ? italicAxisSetting.value > 0
        ? 'italic'
        : 'normal'
      : slantAxisSetting !== undefined
        ? slantAxisSetting.value !== 0
          ? 'italic'
          : 'normal'
        : font.style;

  return {
    ...font,
    variantType: 'variable',
    axisSettings: axisSettings ?? null,
    weight: weightAxisSetting ? String(weightAxisSetting.value) : font.weight,
    style,
  };
};

export const hydrateFontForUi = (
  font: AssetLibraryFont,
  storedAxisSelections: StoredAxisSelections,
): AssetLibraryFont => {
  if (!isVariableFont(font)) {
    return {
      ...font,
      variantType: 'static',
      axes: font.axes ?? null,
      axisSettings: null,
    };
  }

  const nextAxisSettings = sanitizeAxisSettings(
    font,
    storedAxisSelections[font.id] ?? getDefaultAxisSettings(font),
  );

  return syncFontDefaultsFromAxisSettings(
    {
      ...font,
      variantType: 'variable',
      axes: font.axes ?? null,
    },
    nextAxisSettings,
  );
};

export const getFontVariationSettings = (
  font: AssetLibraryFont,
): string | undefined => {
  if (font.variantType !== 'variable' || !font.axes?.length) {
    return undefined;
  }

  return font.axes
    .map((axis) => `"${axis.tag}" ${getAxisSettingValue(font, axis)}`)
    .join(', ');
};

export const getFontPreviewStyle = (font: AssetLibraryFont): CSSProperties => ({
  fontFamily: `"${font.family}", system-ui, sans-serif`,
  fontWeight: font.weight,
  fontStyle: font.style,
  fontVariationSettings: getFontVariationSettings(font),
});
