import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/** Filename for brand kit configuration in the project root. */
export const BRAND_KIT_CONFIG_FILENAME = 'canvas.brand-kit.json';

/**
 * Server-side pattern for CSS custom property names.
 *
 * Mirrors the Regex constraint on `canvas.color.*` `cssVariable` in
 * config/schema/canvas.schema.yml.
 */
export const CSS_VARIABLE_PATTERN = /^--[a-zA-Z_-][a-zA-Z0-9_-]*$/;

/** Six- or eight-digit hex color string accepted in the brand kit file. */
export const HEX_COLOR_PATTERN = /^#([0-9a-fA-F]{6})([0-9a-fA-F]{2})?$/;

/**
 * Color value in W3C design token format, as stored on `canvas.color.*`
 * config entities and returned by the brand kit HTTP API.
 */
export interface ColorTokenValue {
  colorSpace: 'srgb' | 'hsl';
  components: number[];
  alpha?: number | null;
  hex?: string | null;
}

/**
 * A color value as written in canvas.brand-kit.json: either a hex string
 * (`#rrggbb` or `#rrggbbaa`) or the full design token object.
 */
export type BrandKitColorFileValue = string | ColorTokenValue;

/** A single entry in the `colors` array of canvas.brand-kit.json. */
export interface BrandKitColorFileEntry {
  name: string;
  cssVariable: string;
  value: BrandKitColorFileValue;
  displayFormat?: 'rgb' | 'hex' | 'hsl' | null;
}

/**
 * Parses a `#rrggbb`/`#rrggbbaa` string into a design token value.
 * Returns null when the string is not a valid hex color.
 *
 * Matches what the editor UI stores when a user picks a hex color:
 * components as channel / 255 floats, alpha null when opaque and rounded
 * to two decimals otherwise, and the six-digit hex preserved.
 */
export function parseHexColor(value: string): ColorTokenValue | null {
  const match = HEX_COLOR_PATTERN.exec(value);
  if (!match) {
    return null;
  }
  const rgb = match[1];
  const r = parseInt(rgb.slice(0, 2), 16);
  const g = parseInt(rgb.slice(2, 4), 16);
  const b = parseInt(rgb.slice(4, 6), 16);
  let alpha: number | null = null;
  if (match[2] !== undefined) {
    const a = parseInt(match[2], 16) / 255;
    alpha = a === 1 ? null : Math.round(a * 100) / 100;
  }
  return {
    colorSpace: 'srgb',
    components: [r / 255, g / 255, b / 255],
    alpha,
    hex: `#${rgb}`,
  };
}

/**
 * Normalizes a file value (hex string or token object) to a token object.
 * Returns null for a malformed hex string; object values pass through
 * unchecked (strict validation is the CLI's job).
 */
export function normalizeColorValue(
  value: BrandKitColorFileValue,
): ColorTokenValue | null {
  if (typeof value === 'string') {
    return parseHexColor(value);
  }
  return value;
}

const EPSILON = 1e-9;

function numbersEqual(a: number, b: number): boolean {
  return Math.abs(a - b) < EPSILON;
}

/**
 * Semantic equality between two token values: same color space, numerically
 * equal components, equal effective alpha (absent, null, and 1 are all
 * opaque), and — only when both sides carry one — case-insensitively equal
 * hex. A one-sided hex is ignored because it is a cached display value the
 * server derives from (and clears alongside) the components.
 */
export function colorTokenValuesEqual(
  a: ColorTokenValue,
  b: ColorTokenValue,
): boolean {
  if (a.colorSpace !== b.colorSpace) {
    return false;
  }
  if (a.components.length !== b.components.length) {
    return false;
  }
  for (let i = 0; i < a.components.length; i++) {
    if (!numbersEqual(a.components[i], b.components[i])) {
      return false;
    }
  }
  if (!numbersEqual(a.alpha ?? 1, b.alpha ?? 1)) {
    return false;
  }
  if (a.hex != null && b.hex != null) {
    return a.hex.toLowerCase() === b.hex.toLowerCase();
  }
  return true;
}

/**
 * Serializes a token value for canvas.brand-kit.json: the plain hex string
 * when that is lossless (opaque sRGB with a stored hex), otherwise a token
 * object with a fixed key order and only the keys that carry information.
 */
export function serializeColorValue(
  token: ColorTokenValue,
): BrandKitColorFileValue {
  const opaque = token.alpha == null || numbersEqual(token.alpha, 1);
  if (token.colorSpace === 'srgb' && token.hex != null && opaque) {
    return token.hex;
  }
  const out: ColorTokenValue = {
    colorSpace: token.colorSpace,
    components: token.components,
  };
  if (!opaque) {
    out.alpha = token.alpha;
  }
  if (token.hex != null) {
    out.hex = token.hex;
  }
  return out;
}

function channelTo255(component: number): number {
  return Math.round(component * 255);
}

function computedHex(components: number[]): string {
  return `#${components
    .slice(0, 3)
    .map((c) => channelTo255(c).toString(16).padStart(2, '0'))
    .join('')}`;
}

/**
 * Converts a token value to the CSS color string the product renders.
 * Mirrors ui/src/utils/brandKitColor.ts `getCssColorValue()` (and the PHP
 * `Color::getCssValue()`): stored hex preferred for opaque sRGB, `rgba()`
 * with two-decimal alpha, `hsl()`/`hsla()` with rounded components.
 */
export function colorTokenToCss(token: ColorTokenValue): string {
  const alpha = token.alpha ?? 1;
  const roundedAlpha = Math.round(alpha * 100) / 100;

  switch (token.colorSpace) {
    case 'hsl': {
      const h = Math.round(token.components[0] ?? 0);
      const s = Math.round(token.components[1] ?? 0);
      const l = Math.round(token.components[2] ?? 0);
      return roundedAlpha === 1
        ? `hsl(${h}, ${s}%, ${l}%)`
        : `hsla(${h}, ${s}%, ${l}%, ${roundedAlpha})`;
    }
    case 'srgb':
    default: {
      const hex = token.hex ?? computedHex(token.components);
      if (roundedAlpha === 1) {
        return hex;
      }
      const r = parseInt(hex.slice(1, 3), 16);
      const g = parseInt(hex.slice(3, 5), 16);
      const b = parseInt(hex.slice(5, 7), 16);
      return `rgba(${r}, ${g}, ${b}, ${roundedAlpha})`;
    }
  }
}

/**
 * Builds the `:root` custom property block for a set of brand kit colors,
 * in array order (the file's order is the palette order). Entries whose
 * `cssVariable` does not match the server's pattern or whose value cannot
 * be parsed are skipped; an empty result is the empty string.
 */
export function buildBrandKitColorCss(
  entries: BrandKitColorFileEntry[],
): string {
  const properties: string[] = [];
  for (const entry of entries) {
    if (
      typeof entry?.cssVariable !== 'string' ||
      !CSS_VARIABLE_PATTERN.test(entry.cssVariable)
    ) {
      continue;
    }
    const token = normalizeColorValue(entry.value);
    if (!token || !Array.isArray(token.components)) {
      continue;
    }
    properties.push(`  ${entry.cssVariable}: ${colorTokenToCss(token)};`);
  }
  if (properties.length === 0) {
    return '';
  }
  return `:root {\n${properties.join('\n')}\n}`;
}

/**
 * Leniently reads the `colors` array from canvas.brand-kit.json in the
 * given project root. A missing file, invalid JSON, or an absent/non-array
 * `colors` key all yield an empty array — callers that need strict
 * validation (the CLI) do their own.
 */
export function readBrandKitColors(hostRoot: string): BrandKitColorFileEntry[] {
  let raw: string;
  try {
    raw = readFileSync(resolve(hostRoot, BRAND_KIT_CONFIG_FILENAME), 'utf-8');
  } catch {
    return [];
  }
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return [];
  }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return [];
  }
  const colors = (parsed as { colors?: unknown }).colors;
  if (!Array.isArray(colors)) {
    return [];
  }
  return colors.filter(
    (entry): entry is BrandKitColorFileEntry =>
      !!entry && typeof entry === 'object' && !Array.isArray(entry),
  );
}
