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

/** Prefix used for brand kit color references in stored values. */
export const CANVAS_COLOR_REF_PREFIX = 'canvas-color:';

/** JSON Schema $ref for Canvas color props. */
export const COLOR_PROP_SCHEMA_REF =
  'json-schema-definitions://canvas.module/color';

/**
 * UUID pattern used to distinguish canvas-color:<uuid> from canvas-color:<cssVarKey>.
 * Case-insensitive (standard 8-4-4-4-12 format).
 */
export const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** Formats a color name and CSS variable for display in CLI output. */
export const itemName = (name: string, cssVariable: string): string =>
  `${name} (${cssVariable})`;

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
 * Color config entity as returned by the HTTP API.
 * Matches the backend `canvas.color.*` config entity structure.
 */
export interface BrandKitColorEntry {
  /** Server-assigned id (the entity UUID). */
  id: string;
  name: string;
  cssVariable: string;
  value: ColorTokenValue;
  displayFormat?: 'rgb' | 'hex' | 'hsl' | null;
  weight: number;
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
 * Parses `#rrggbb`/`#rrggbbaa` into a token the way the editor UI stores it:
 * channel / 255 floats, six-digit hex kept, alpha null when opaque and the
 * exact `aa / 255` otherwise (rounding would turn `#cc000001` into 0).
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

// Strict decimals (a lax `[\d.]+` would truncate junk like "0.5.6"); legacy
// comma and modern space-and-slash syntax stay separate patterns, matching
// CSS; rgb() channels follow the CSS <number-percentage> grammar.
const RGB_LEGACY_PATTERN =
  /^rgba?\(\s*((?:\d{1,3}(?:\.\d+)?|\.\d+)%?)\s*,\s*((?:\d{1,3}(?:\.\d+)?|\.\d+)%?)\s*,\s*((?:\d{1,3}(?:\.\d+)?|\.\d+)%?)\s*(?:,\s*((?:\d+(?:\.\d+)?|\.\d+)%?)\s*)?\)$/i;
const RGB_MODERN_PATTERN =
  /^rgba?\(\s*((?:\d{1,3}(?:\.\d+)?|\.\d+)%?)\s+((?:\d{1,3}(?:\.\d+)?|\.\d+)%?)\s+((?:\d{1,3}(?:\.\d+)?|\.\d+)%?)\s*(?:\/\s*((?:\d+(?:\.\d+)?|\.\d+)%?)\s*)?\)$/i;
const HSL_LEGACY_PATTERN =
  /^hsla?\(\s*(-?(?:\d+(?:\.\d+)?|\.\d+))(?:deg)?\s*,\s*(-?(?:\d+(?:\.\d+)?|\.\d+))%\s*,\s*(-?(?:\d+(?:\.\d+)?|\.\d+))%\s*(?:,\s*((?:\d+(?:\.\d+)?|\.\d+)%?)\s*)?\)$/i;
const HSL_MODERN_PATTERN =
  /^hsla?\(\s*(-?(?:\d+(?:\.\d+)?|\.\d+))(?:deg)?\s+(-?(?:\d+(?:\.\d+)?|\.\d+))%\s+(-?(?:\d+(?:\.\d+)?|\.\d+))%\s*(?:\/\s*((?:\d+(?:\.\d+)?|\.\d+)%?)\s*)?\)$/i;

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

  const rgbLegacyMatch = RGB_LEGACY_PATTERN.exec(value);
  const rgbMatch = rgbLegacyMatch ?? RGB_MODERN_PATTERN.exec(value);
  if (rgbMatch) {
    const raw = [rgbMatch[1], rgbMatch[2], rgbMatch[3]];
    // Legacy comma syntax requires all channels to share one type; the
    // modern syntax may mix numbers and percentages (CSS Color 4).
    const percentCount = raw.filter((c) => c.endsWith('%')).length;
    if (rgbLegacyMatch && percentCount !== 0 && percentCount !== 3) {
      return null;
    }
    const components = raw.map((c) =>
      c.endsWith('%') ? Number.parseFloat(c) / 100 : Number.parseFloat(c) / 255,
    );
    if (components.some((c) => !Number.isFinite(c) || c > 1)) {
      return null;
    }
    const alpha = parseAlphaString(rgbMatch[4]);
    if (Number.isNaN(alpha)) {
      return null;
    }
    return {
      token: {
        colorSpace: 'srgb',
        components,
        alpha,
        hex: computedHex(components),
      },
      displayFormat: 'rgb',
    };
  }

  const hslMatch =
    HSL_LEGACY_PATTERN.exec(value) ?? HSL_MODERN_PATTERN.exec(value);
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
 * Normalizes a file value (CSS string or token object) to a token, or null
 * for anything malformed, so renderers skip hand-edited junk instead of
 * emitting invalid CSS. Useful error messages are the CLI validator's job.
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
 * Semantic token equality: same space and components, same effective alpha
 * (absent/null/1 are all opaque), hex compared case-insensitively and only
 * when both sides carry one (it is a cached display value).
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
    const ai = a.components[i];
    const bi = b.components[i];
    // sRGB components are always n/255 (8-bit channel values); compare by
    // rounded channel to absorb float serialization noise (PHP
    // serialize_precision, YAML rounding by external tools).
    const equal =
      a.colorSpace === 'srgb'
        ? Math.round(ai * 255) === Math.round(bi * 255)
        : numbersEqual(ai, bi);
    if (!equal) {
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
 * Serializes a token for canvas.brand-kit.json as a CSS color string.
 *
 * The file keeps human-authored formats (`hex`, `rgb`, `hsl`) readable while
 * token components remain an internal API detail.
 */
export function serializeColorValue(
  token: ColorTokenValue,
  preferredFormat?: ColorDisplayFormat | null,
): BrandKitColorFileValue {
  const candidate = serializeCandidateString(token, preferredFormat);
  if (candidate !== null) {
    return candidate;
  }
  return computedHex(token.components);
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
 * Leniently normalizes a raw `colors` map: junk values yield a null token,
 * unusable keys are skipped. Strict validation is the CLI's job.
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
 * Builds the `:root` custom property block in map order. Unparseable
 * entries are skipped; an empty result is the empty string.
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
 * Leniently reads and normalizes the `colors` map from canvas.brand-kit.json;
 * a missing file, invalid JSON, or non-object `colors` yields an empty array.
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

export type ColorRefTransformDirection = 'toVarKey' | 'toUuid';

/**
 * Transforms a color reference string between authored (cssVarKey) and
 * server (UUID) formats.
 *
 * - `toVarKey`: canvas-color:<uuid> → canvas-color:<cssVarKey> (for pull)
 * - `toUuid`: canvas-color:<cssVarKey> → canvas-color:<uuid> (for push)
 *
 * Non-color values and already-transformed references pass through unchanged.
 *
 * @param value - The prop value to potentially transform
 * @param lookup - Map keyed by the source format (UUID for toVarKey, cssVariable for toUuid)
 * @param direction - Transform direction
 */
export function transformColorRef(
  value: unknown,
  lookup: Map<string, BrandKitColorEntry>,
  direction: ColorRefTransformDirection,
): unknown {
  if (typeof value !== 'string') {
    return value;
  }

  if (!value.startsWith(CANVAS_COLOR_REF_PREFIX)) {
    return value;
  }

  const ref = value.slice(CANVAS_COLOR_REF_PREFIX.length);
  const isUuid = UUID_PATTERN.test(ref);

  if (direction === 'toVarKey') {
    // Pull: UUID → cssVarKey. Skip if already a cssVarKey.
    if (!isUuid) {
      return value;
    }
    const entry = lookup.get(ref);
    if (!entry) {
      return value; // UUID not found; preserve to avoid data loss
    }
    const cssVarKey = entry.cssVariable.startsWith('--')
      ? entry.cssVariable.slice(2)
      : entry.cssVariable;
    return `${CANVAS_COLOR_REF_PREFIX}${cssVarKey}`;
  }

  // Push: cssVarKey → UUID. Skip if already a UUID.
  if (isUuid) {
    return value;
  }
  const entry = lookup.get(`--${ref}`);
  if (!entry) {
    return value; // Color not on server; Drupal will reject appropriately
  }
  return `${CANVAS_COLOR_REF_PREFIX}${entry.id}`;
}

/**
 * Transforms color examples in a component props schema.
 *
 * Iterates `props.properties`, finds color props by `$ref`, and applies
 * `transformColorRef()` to the first example value.
 *
 * @param props - Component props schema object with `properties`
 * @param lookup - Color lookup map (keyed appropriately for direction)
 * @param direction - Transform direction
 */
export function transformColorExamplesInProps(
  props: Record<string, unknown> | null | undefined,
  lookup: Map<string, BrandKitColorEntry>,
  direction: ColorRefTransformDirection,
): Record<string, unknown> | null | undefined {
  if (!props) {
    return props;
  }
  const properties = props.properties as Record<string, unknown> | undefined;
  if (!properties) {
    return props;
  }

  const transformedProperties: Record<string, unknown> = {};

  for (const [propName, propDefinition] of Object.entries(properties)) {
    if (
      typeof propDefinition === 'object' &&
      propDefinition !== null &&
      '$ref' in propDefinition &&
      (propDefinition as { $ref?: string }).$ref === COLOR_PROP_SCHEMA_REF
    ) {
      const def = propDefinition as {
        examples?: unknown[];
        [key: string]: unknown;
      };
      if (Array.isArray(def.examples) && def.examples.length > 0) {
        const transformed = transformColorRef(
          def.examples[0],
          lookup,
          direction,
        );
        transformedProperties[propName] = {
          ...def,
          examples: [transformed],
        };
      } else {
        transformedProperties[propName] = def;
      }
    } else {
      transformedProperties[propName] = propDefinition;
    }
  }

  return { ...props, properties: transformedProperties };
}
