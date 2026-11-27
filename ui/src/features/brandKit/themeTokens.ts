import { getCssColorValue } from '@/utils/brandKitColor';

import type {
  BrandKitColorValue,
  BrandKitThemeToken,
  ThemeTokenDimension,
  ThemeTokenType,
  ThemeTokenTypography,
} from '@/types/CodeComponent';

const KNOWN_TYPES: readonly string[] = [
  'color',
  'fontFamily',
  'fontWeight',
  'dimension',
  'number',
  'typography',
] satisfies ThemeTokenType[];

/** Types the style guide can edit until phase 1b adds the others. */
export const EDITABLE_TYPES: readonly string[] = ['color', 'fontFamily'];

export const isKnownTokenType = (type: string): type is ThemeTokenType =>
  KNOWN_TYPES.includes(type);

/**
 * CSS identifiers separated by single spaces. Generic families match too.
 *
 * @see \Drupal\canvas\DesignToken\BrandKitTokens::FONT_FAMILY
 */
const FONT_FAMILY = /^-?[A-Za-z_][A-Za-z0-9_-]*( -?[A-Za-z_][A-Za-z0-9_-]*)*$/;

export const FONT_FAMILY_ERROR =
  "Family names with digits at the start of a word or with punctuation aren't supported yet.";

export const isValidFontFamily = (family: string): boolean =>
  FONT_FAMILY.test(family);

const dimensionToCss = ({ value, unit }: ThemeTokenDimension) =>
  `${value}${unit}`;

/**
 * Serializes a token value as CSS, the way core's `toCss()` does.
 *
 * Typography is the `font` shorthand, which has no letter spacing.
 *
 * @returns The CSS value, or null for an unknown type or a missing value.
 */
export const tokenValueToCss = (
  type: string,
  value: unknown,
): string | null => {
  if (value === null || value === undefined || !isKnownTokenType(type)) {
    return null;
  }
  // Values other tools stored may be malformed: never let one break the UI.
  try {
    return serialize(type, value);
  } catch {
    return null;
  }
};

const serialize = (type: ThemeTokenType, value: unknown): string | null => {
  switch (type) {
    case 'color':
      return getCssColorValue(value as BrandKitColorValue);
    case 'fontFamily':
      return (value as string[]).join(', ');
    case 'dimension':
      return dimensionToCss(value as ThemeTokenDimension);
    case 'fontWeight':
    case 'number':
      return String(value);
    case 'typography': {
      const t = value as ThemeTokenTypography;
      return `${t.fontWeight} ${dimensionToCss(t.fontSize)}/${t.lineHeight} ${t.fontFamily.join(', ')}`;
    }
  }
};

/**
 * The value the editor preview shows: a draft wins, a staged reset shows the
 * declared value.
 */
export const draftEffectiveValue = (token: BrandKitThemeToken): unknown => {
  if (token.draft !== null) {
    return token.draft.value ?? token.declaredValue;
  }
  return token.overridden ? token.value : token.declaredValue;
};

/**
 * Paths emitted in this document. A path stays emitted until the next full
 * load, so a stale staged value in the iframe's draft stylesheet never shows
 * through after its draft is discarded.
 */
const emittedPaths = new Set<string>();

/**
 * Builds the `:root` block the editor previews inject after theme CSS.
 *
 * @param tokens - The brand kit's theme tokens.
 * @param includeDeclared - Emit every listed token, for previews that load no
 *   theme CSS (the code editor). Otherwise only overridden or drafted ones.
 */
export const buildTokenStyles = (
  tokens: BrandKitThemeToken[] | null | undefined,
  { includeDeclared }: { includeDeclared: boolean },
): string => {
  const properties: string[] = [];
  for (const token of tokens ?? []) {
    if (!isKnownTokenType(token.type)) {
      continue;
    }
    const emit =
      includeDeclared ||
      token.overridden ||
      token.draft !== null ||
      emittedPaths.has(token.path);
    if (!emit) {
      continue;
    }
    const css = tokenValueToCss(token.type, draftEffectiveValue(token));
    if (css !== null) {
      if (!includeDeclared) {
        emittedPaths.add(token.path);
      }
      properties.push(`  ${token.cssVariable}: ${css};`);
    }
  }
  return properties.length ? `:root {\n${properties.join('\n')}\n}` : '';
};

/** "Copy CSS variable": typography is used through the `font` shorthand. */
export const cssVariableSnippet = (token: BrandKitThemeToken): string =>
  token.type === 'typography'
    ? `font: var(${token.cssVariable})`
    : `var(${token.cssVariable})`;

/** Parses a `{path}` theme color reference. */
export const parseTokenReference = (value: string): string | null => {
  const match = /^\{([^{}\s][^{}]*)\}$/.exec(value);
  return match ? match[1] : null;
};

/** Parses `#rgb` or `#rrggbb` into the DTCG color shape, or null. */
export const hexToColorValue = (hex: string): BrandKitColorValue | null => {
  const match = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(hex.trim());
  if (!match) {
    return null;
  }
  const digits =
    match[1].length === 3
      ? match[1].replace(/./g, (d) => d + d)
      : match[1].toLowerCase();
  const [r, g, b] = [0, 2, 4].map(
    (i) => parseInt(digits.slice(i, i + 2), 16) / 255,
  );
  return {
    colorSpace: 'srgb',
    components: [r, g, b],
    alpha: null,
    hex: `#${digits.toLowerCase()}`,
  };
};
