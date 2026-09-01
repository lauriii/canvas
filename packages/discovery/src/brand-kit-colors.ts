import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/** Filename for brand kit configuration in the project root. */
export const BRAND_KIT_CONFIG_FILENAME = 'canvas.brand-kit.json';

/**
 * Published URL of the brand kit JSON Schema, written as `$schema` into
 * newly created files so editors validate and autocomplete them.
 */
export const BRAND_KIT_SCHEMA_URL =
  'https://unpkg.com/@drupal-canvas/workbench/dist/client/src/lib/schemas/brand-kit.schema.json';

/**
 * Server-side pattern for CSS custom property names.
 *
 * Mirrors the Regex constraint on `canvas.color.*` `cssVariable` in
 * config/schema/canvas.schema.yml.
 */
export const CSS_VARIABLE_PATTERN = /^--[a-zA-Z_-][a-zA-Z0-9_-]*$/;

/**
 * Pattern for a color key in the `colors` map: the CSS custom property name
 * with or without its `--` prefix. Stripping or adding the prefix maps
 * one-to-one onto the server's `cssVariable` pattern.
 */
export const COLOR_KEY_PATTERN = /^(--)?[a-zA-Z_-][a-zA-Z0-9_-]*$/;

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
 * A color value in canvas.brand-kit.json: a CSS color string (`#rrggbb`,
 * `#rrggbbaa`, `rgb()`, `rgba()`, `hsl()`, or `hsla()`) or the full design
 * token object for exact component values.
 */
export type BrandKitColorFileValue = string | ColorTokenValue;

/**
 * The wrapper form of a `colors` map entry, used when an entry needs more
 * than its value: a display name differing from the one derived from the
 * key, or an explicit editor display format.
 */
export interface BrandKitColorFileObject {
  value: BrandKitColorFileValue;
  name?: string;
  displayFormat?: 'rgb' | 'hex' | 'hsl' | null;
}

/**
 * The `colors` key of canvas.brand-kit.json: a map from color key (the CSS
 * custom property name, `--` prefix optional) to a value or wrapper object,
 * in palette order — the shape of a Tailwind theme or a flat W3C design
 * tokens document.
 */
export type BrandKitColorsFileMap = Record<
  string,
  BrandKitColorFileValue | BrandKitColorFileObject
>;

export type ColorDisplayFormat = 'rgb' | 'hex' | 'hsl';

/**
 * A `colors` map entry normalized for the sync engine and CSS generation.
 */
export interface NormalizedBrandKitColor {
  /** The map key exactly as written in the file. */
  rawKey: string;
  /** The key without a `--` prefix. */
  key: string;
  /** The CSS custom property name (`--` + key). */
  cssVariable: string;
  /** Display name: the explicit one, or derived from the key. */
  name: string;
  /** Set only when the file asserts a name via the wrapper form. */
  explicitName?: string;
  /** Set only when the file asserts a display format via the wrapper form. */
  explicitDisplayFormat?: ColorDisplayFormat | null;
  /** Display format implied by the value's string form, when any. */
  derivedDisplayFormat?: ColorDisplayFormat;
  /** Parsed token value, or null when the value cannot be parsed. */
  token: ColorTokenValue | null;
  /** The map value exactly as written in the file. */
  rawValue: BrandKitColorFileValue | BrandKitColorFileObject;
}

/**
 * Normalizes a `colors` map key: strips an optional `--` prefix. Returns
 * null when the key cannot map onto a valid CSS custom property name.
 */
export function normalizeColorKey(key: string): string | null {
  if (typeof key !== 'string') {
    return null;
  }
  const bare = key.startsWith('--') ? key.slice(2) : key;
  return /^[a-zA-Z_-][a-zA-Z0-9_-]*$/.test(bare) ? bare : null;
}

/** Returns the CSS custom property name for a normalized color key. */
export function keyToCssVariable(key: string): string {
  return `--${key}`;
}

/**
 * Derives a display name from a color key: `brand-red` becomes "Brand Red".
 * The server requires every color to have a name; deriving it keeps the
 * common file entry to a single line.
 */
export function deriveColorName(key: string): string {
  return key
    .split(/[-_]+/)
    .filter((word) => word.length > 0)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

/**
 * Parses a `#rrggbb`/`#rrggbbaa` string into a design token value.
 * Returns null when the string is not a valid hex color.
 *
 * Matches what the editor UI stores when a user picks a hex color:
 * components as channel / 255 floats, alpha null when opaque, and the
 * six-digit hex preserved. Alpha keeps its exact `aa / 255` value — rounding
 * would turn `#cc000001` into 0 — so renderers round only when producing CSS.
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
    alpha = a === 1 ? null : a;
  }
  return {
    colorSpace: 'srgb',
    components: [r / 255, g / 255, b / 255],
    alpha,
    hex: `#${rgb}`,
  };
}

// Strict decimal tokens: `parseFloat()` consumes only a valid prefix, so a
// permissive `[\d.]+` would accept junk like "0.5.6" and quietly truncate.
const RGB_PATTERN =
  /^rgba?\(\s*(\d{1,3})\s*(?:,|\s)\s*(\d{1,3})\s*(?:,|\s)\s*(\d{1,3})\s*(?:(?:,|\/)\s*((?:\d+(?:\.\d+)?|\.\d+)%?)\s*)?\)$/;
const HSL_PATTERN =
  /^hsla?\(\s*(-?(?:\d+(?:\.\d+)?|\.\d+))(?:deg)?\s*(?:,|\s)\s*(-?(?:\d+(?:\.\d+)?|\.\d+))%\s*(?:,|\s)\s*(-?(?:\d+(?:\.\d+)?|\.\d+))%\s*(?:(?:,|\/)\s*((?:\d+(?:\.\d+)?|\.\d+)%?)\s*)?\)$/;

function parseAlphaString(raw: string | undefined): number | null {
  if (raw === undefined) {
    return null;
  }
  const value = raw.endsWith('%')
    ? Number.parseFloat(raw.slice(0, -1)) / 100
    : Number.parseFloat(raw);
  if (!Number.isFinite(value) || value < 0 || value > 1) {
    return Number.NaN;
  }
  return value === 1 ? null : value;
}

/**
 * Parses a CSS color string — hex, `rgb()`, `rgba()`, `hsl()`, or `hsla()`,
 * in comma or space syntax — into a design token value plus the editor
 * display format the string form implies. Returns null for anything else.
 */
export function parseCssColorString(
  value: string,
): { token: ColorTokenValue; displayFormat: ColorDisplayFormat } | null {
  const hexToken = parseHexColor(value);
  if (hexToken) {
    return { token: hexToken, displayFormat: 'hex' };
  }

  const rgbMatch = RGB_PATTERN.exec(value);
  if (rgbMatch) {
    const channels = [rgbMatch[1], rgbMatch[2], rgbMatch[3]].map((c) =>
      parseInt(c, 10),
    );
    if (channels.some((c) => c > 255)) {
      return null;
    }
    const alpha = parseAlphaString(rgbMatch[4]);
    if (Number.isNaN(alpha)) {
      return null;
    }
    return {
      token: {
        colorSpace: 'srgb',
        components: channels.map((c) => c / 255),
        alpha,
        hex: computedHex(channels.map((c) => c / 255)),
      },
      displayFormat: 'rgb',
    };
  }

  const hslMatch = HSL_PATTERN.exec(value);
  if (hslMatch) {
    const components = [hslMatch[1], hslMatch[2], hslMatch[3]].map((c) =>
      Number.parseFloat(c),
    );
    // Hue is an unbounded angle; saturation and lightness are percentages.
    if (
      components.some((c) => !Number.isFinite(c)) ||
      components[1] < 0 ||
      components[1] > 100 ||
      components[2] < 0 ||
      components[2] > 100
    ) {
      return null;
    }
    const alpha = parseAlphaString(hslMatch[4]);
    if (Number.isNaN(alpha)) {
      return null;
    }
    // No hex is computed for HSL: the equality comparator ignores a
    // one-sided hex, and every renderer derives CSS from the components.
    return {
      token: { colorSpace: 'hsl', components, alpha, hex: null },
      displayFormat: 'hsl',
    };
  }

  return null;
}

/**
 * Normalizes a file value (CSS color string or token object) to a token
 * object. Returns null for a malformed string, a missing value, or an
 * object that is not a well-formed token — wrong color space, components
 * that are not three finite numbers, or an out-of-range alpha — so callers
 * can treat hand-edited junk as "no parseable value" instead of rendering
 * invalid CSS (strict validation with useful messages is the CLI's job).
 */
export function normalizeColorValue(
  value: BrandKitColorFileValue | null | undefined,
): ColorTokenValue | null {
  if (value == null) {
    return null;
  }
  if (typeof value === 'string') {
    return parseCssColorString(value)?.token ?? null;
  }
  if (
    typeof value === 'object' &&
    !Array.isArray(value) &&
    (value.colorSpace === 'srgb' || value.colorSpace === 'hsl') &&
    Array.isArray(value.components) &&
    value.components.length === 3 &&
    value.components.every(
      (c) => typeof c === 'number' && Number.isFinite(c),
    ) &&
    (value.alpha == null ||
      (typeof value.alpha === 'number' && value.alpha >= 0 && value.alpha <= 1))
  ) {
    return value;
  }
  return null;
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
 * Serializes a token value for canvas.brand-kit.json: a CSS color string
 * when one parses back to the exact same token — hex for opaque sRGB,
 * `rgba()` for translucent sRGB, `hsl()`/`hsla()` for HSL — otherwise the
 * token object with a fixed key order and only the keys that carry
 * information. The parse-back check makes the lossless guarantee structural: any
 * value another API client wrote that a string cannot represent exactly
 * stays an object, so the next push never rewrites a color the user did
 * not touch.
 */
export function serializeColorValue(
  token: ColorTokenValue,
  preferredFormat?: ColorDisplayFormat | null,
): BrandKitColorFileValue {
  const candidate = serializeCandidateString(token, preferredFormat);
  if (candidate !== null) {
    const parsed = parseCssColorString(candidate)?.token;
    if (
      parsed &&
      exactTokensEqual(parsed, token) &&
      (token.hex == null ||
        parsed.hex == null ||
        parsed.hex.toLowerCase() === token.hex.toLowerCase())
    ) {
      return candidate;
    }
  }
  const out: ColorTokenValue = {
    colorSpace: token.colorSpace,
    components: token.components,
  };
  const opaque = token.alpha == null || token.alpha === 1;
  if (!opaque) {
    out.alpha = token.alpha;
  }
  if (token.hex != null) {
    out.hex = token.hex;
  }
  return out;
}

function serializeCandidateString(
  token: ColorTokenValue,
  preferredFormat?: ColorDisplayFormat | null,
): string | null {
  const opaque = token.alpha == null || token.alpha === 1;
  if (token.colorSpace === 'srgb') {
    // An opaque color the editor displays as RGB keeps its rgb() form, so
    // the string carries the display format too.
    if (opaque && preferredFormat === 'rgb' && token.components.length >= 3) {
      const channels = token.components
        .slice(0, 3)
        .map((c) => Math.round(c * 255));
      return `rgb(${channels.join(', ')})`;
    }
    if (opaque && token.hex != null) {
      return token.hex;
    }
    if (!opaque && token.components.length >= 3) {
      const channels = token.components
        .slice(0, 3)
        .map((c) => Math.round(c * 255));
      return `rgba(${channels.join(', ')}, ${String(token.alpha)})`;
    }
    return null;
  }
  if (token.colorSpace === 'hsl' && token.components.length >= 3) {
    const [h, s, l] = token.components.map(String);
    return opaque
      ? `hsl(${h}, ${s}%, ${l}%)`
      : `hsla(${h}, ${s}%, ${l}%, ${String(token.alpha)})`;
  }
  return null;
}

/**
 * Exact structural equality for the serialize round-trip check: components
 * and alpha must reproduce bit-for-bit, not within an epsilon, or the
 * string form would change the stored value on the next push. Absent, null,
 * and 1 alpha are all the same opacity.
 */
function exactTokensEqual(a: ColorTokenValue, b: ColorTokenValue): boolean {
  if (a.colorSpace !== b.colorSpace) {
    return false;
  }
  if (a.components.length !== b.components.length) {
    return false;
  }
  for (let i = 0; i < a.components.length; i++) {
    if (a.components[i] !== b.components[i]) {
      return false;
    }
  }
  return (a.alpha ?? 1) === (b.alpha ?? 1);
}

function channelTo255(component: number): number {
  // Clamp so out-of-range components (which the server schema does not
  // constrain) can never emit an invalid hex like a seven-digit string.
  return Math.min(255, Math.max(0, Math.round(component * 255)));
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
      // Only trust a well-formed stored hex; the lenient Workbench path can
      // see hand-edited junk here.
      const hex =
        token.hex != null && /^#[0-9a-fA-F]{6}$/.test(token.hex)
          ? token.hex
          : computedHex(token.components);
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
 * Leniently normalizes a raw `colors` map for the sync engine and CSS
 * generation: invalid keys and junk values yield entries with a null token
 * (or are skipped when the key cannot name a variable at all). Strict
 * validation with useful messages is the CLI's job.
 */
export function normalizeBrandKitColors(
  map: unknown,
): NormalizedBrandKitColor[] {
  if (!map || typeof map !== 'object' || Array.isArray(map)) {
    return [];
  }
  const colors: NormalizedBrandKitColor[] = [];
  for (const [rawKey, rawValue] of Object.entries(
    map as Record<string, unknown>,
  )) {
    const key = normalizeColorKey(rawKey);
    if (key === null) {
      continue;
    }
    let value: BrandKitColorFileValue | null | undefined;
    let explicitName: string | undefined;
    let explicitDisplayFormat: ColorDisplayFormat | null | undefined;
    if (
      rawValue &&
      typeof rawValue === 'object' &&
      !Array.isArray(rawValue) &&
      'value' in rawValue
    ) {
      const wrapper = rawValue as BrandKitColorFileObject;
      value = wrapper.value;
      if (typeof wrapper.name === 'string' && wrapper.name.trim() !== '') {
        explicitName = wrapper.name;
      }
      if ('displayFormat' in wrapper) {
        explicitDisplayFormat = wrapper.displayFormat;
      }
    } else {
      value = rawValue as BrandKitColorFileValue;
    }
    const derivedDisplayFormat =
      typeof value === 'string'
        ? parseCssColorString(value)?.displayFormat
        : undefined;
    colors.push({
      rawKey,
      key,
      cssVariable: keyToCssVariable(key),
      name: explicitName ?? deriveColorName(key),
      explicitName,
      explicitDisplayFormat,
      derivedDisplayFormat,
      token: normalizeColorValue(value),
      rawValue: rawValue as BrandKitColorFileValue | BrandKitColorFileObject,
    });
  }
  return colors;
}

/**
 * Builds the `:root` custom property block for a set of brand kit colors,
 * in map order (the file's order is the palette order). Entries whose value
 * cannot be parsed are skipped; an empty result is the empty string.
 */
export function buildBrandKitColorCss(
  colors: NormalizedBrandKitColor[],
): string {
  const properties: string[] = [];
  for (const color of colors) {
    if (color.token === null) {
      continue;
    }
    properties.push(`  ${color.cssVariable}: ${colorTokenToCss(color.token)};`);
  }
  if (properties.length === 0) {
    return '';
  }
  return `:root {\n${properties.join('\n')}\n}`;
}

/**
 * Leniently reads and normalizes the `colors` map from
 * canvas.brand-kit.json in the given project root. A missing file, invalid
 * JSON, or an absent or non-object `colors` key all yield an empty array.
 */
export function readBrandKitColors(
  hostRoot: string,
): NormalizedBrandKitColor[] {
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
  return normalizeBrandKitColors((parsed as { colors?: unknown }).colors);
}
