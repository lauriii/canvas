import {
  deriveColorName,
  normalizeColorKey,
  parseCssColorString,
} from '@drupal-canvas/discovery';

import type { BrandKitColorsFileMap } from '@drupal-canvas/discovery';

const COLOR_SPACES: ReadonlySet<string> = new Set(['srgb', 'hsl']);
const DISPLAY_FORMATS: ReadonlySet<string> = new Set(['rgb', 'hex', 'hsl']);

/** Server-side pattern for the stored six-digit hex on a token object. */
const STORED_HEX_PATTERN = /^#[0-9a-fA-F]{6}$/;

const VALUE_HINT =
  'Expected a CSS color string like "#cc0000", "rgb(204, 0, 0)", or "hsl(220, 60%, 50%)", or a color object with "colorSpace" and "components".';

function validateTokenObject(
  label: string,
  value: Record<string, unknown>,
  errors: string[],
): void {
  const colorSpace = value.colorSpace;
  if (typeof colorSpace !== 'string' || !COLOR_SPACES.has(colorSpace)) {
    errors.push(
      `${label}: invalid "colorSpace": ${JSON.stringify(colorSpace)}. Expected "srgb" or "hsl".`,
    );
  }
  const components = value.components;
  if (
    !Array.isArray(components) ||
    components.length !== 3 ||
    components.some((c) => typeof c !== 'number' || !Number.isFinite(c))
  ) {
    errors.push(
      `${label}: "components" must be an array of exactly 3 numbers.`,
    );
  } else if (
    colorSpace === 'srgb' &&
    components.some((c: number) => c < 0 || c > 1)
  ) {
    errors.push(`${label}: sRGB components must be between 0 and 1.`);
  } else if (
    colorSpace === 'hsl' &&
    (components[1] < 0 ||
      components[1] > 100 ||
      components[2] < 0 ||
      components[2] > 100)
  ) {
    errors.push(
      `${label}: HSL saturation and lightness must be between 0 and 100.`,
    );
  }
  const alpha = value.alpha;
  if (alpha !== undefined && alpha !== null) {
    if (
      typeof alpha !== 'number' ||
      !Number.isFinite(alpha) ||
      alpha < 0 ||
      alpha > 1
    ) {
      errors.push(`${label}: "alpha" must be between 0 and 1.`);
    }
  }
  const hex = value.hex;
  if (hex !== undefined && hex !== null) {
    if (typeof hex !== 'string' || !STORED_HEX_PATTERN.test(hex)) {
      errors.push(
        `${label}: invalid "hex": ${JSON.stringify(hex)}. Expected a 6-digit hex color like "#cc0000".`,
      );
    }
  }
}

function validateValue(label: string, value: unknown, errors: string[]): void {
  if (typeof value === 'string') {
    if (parseCssColorString(value) === null) {
      errors.push(`${label}: invalid value "${value}". ${VALUE_HINT}`);
    }
    return;
  }
  if (value && typeof value === 'object' && !Array.isArray(value)) {
    validateTokenObject(label, value as Record<string, unknown>, errors);
    return;
  }
  if (value === undefined || value === null) {
    errors.push(`${label}: missing value. ${VALUE_HINT}`);
    return;
  }
  errors.push(
    `${label}: invalid value ${JSON.stringify(value)}. ${VALUE_HINT}`,
  );
}

/**
 * Validates the `colors` map from canvas.brand-kit.json before push: valid
 * color keys, parseable values, well-formed wrapper objects, and unique
 * display names. Mirrors the server-side constraints on `canvas.color.*` —
 * including `UniqueColorNameConstraint`, which compares names trimmed and
 * case-insensitively — so failures happen offline, naming the offending
 * entry. For an entry without an explicit `name` the derived one counts.
 * Throws with all errors listed so the user can fix the file in one go.
 */
export function validateColorsConfig(colors: BrandKitColorsFileMap): void {
  const errors = collectColorConfigErrors(colors);
  if (errors.length > 0) {
    throw new Error(`Color config validation failed:\n${errors.join('\n')}`);
  }
}

/**
 * Collects the validation errors for a `colors` map without throwing, so
 * callers like `canvas validate` can report them per entry.
 */
export function collectColorConfigErrors(
  colors: BrandKitColorsFileMap,
): string[] {
  if (!colors || typeof colors !== 'object' || Array.isArray(colors)) {
    return [
      '"colors" must be an object mapping color keys to values, like { "brand-red": "#cc0000" }.',
    ];
  }

  const errors: string[] = [];
  const seenKeys = new Map<string, string>();
  const seenNames = new Map<string, string>();

  for (const [rawKey, rawValue] of Object.entries(colors)) {
    const label = `Color "${rawKey}"`;

    const key = normalizeColorKey(rawKey);
    if (key === null) {
      errors.push(
        `${label}: invalid color key. Expected a CSS custom property name like "brand-red" or "--brand-red" (letters, digits, hyphens, and underscores, not starting with a digit).`,
      );
      continue;
    }
    const existingKey = seenKeys.get(key);
    if (existingKey !== undefined) {
      errors.push(
        `${label}: duplicate of "${existingKey}" — both name the CSS variable "--${key}".`,
      );
    } else {
      seenKeys.set(key, rawKey);
    }

    // The site requires color names to be unique, compared trimmed and
    // case-insensitively. An entry without an explicit name gets the one
    // derived from its key.
    const explicitName =
      rawValue &&
      typeof rawValue === 'object' &&
      !Array.isArray(rawValue) &&
      'value' in rawValue &&
      typeof (rawValue as { name?: unknown }).name === 'string'
        ? ((rawValue as { name: string }).name as string)
        : undefined;
    const effectiveName = explicitName ?? deriveColorName(key);
    const normalizedName = effectiveName.trim().toLowerCase();
    if (normalizedName !== '') {
      const existingName = seenNames.get(normalizedName);
      if (existingName !== undefined) {
        errors.push(
          `${label}: duplicate name "${effectiveName}" (also used by "${existingName}"). The site requires unique color names; set a different "name".`,
        );
      } else {
        seenNames.set(normalizedName, rawKey);
      }
    }

    if (
      rawValue &&
      typeof rawValue === 'object' &&
      !Array.isArray(rawValue) &&
      'value' in rawValue
    ) {
      const wrapper = rawValue as unknown as Record<string, unknown>;
      if (wrapper.name !== undefined) {
        if (typeof wrapper.name !== 'string' || wrapper.name.trim() === '') {
          errors.push(`${label}: "name" must be a non-empty string.`);
        }
      }
      const displayFormat = wrapper.displayFormat;
      if (
        displayFormat !== undefined &&
        displayFormat !== null &&
        (typeof displayFormat !== 'string' ||
          !DISPLAY_FORMATS.has(displayFormat))
      ) {
        errors.push(
          `${label}: invalid "displayFormat": ${JSON.stringify(displayFormat)}. Expected one of: rgb, hex, hsl.`,
        );
      }
      validateValue(label, wrapper.value, errors);
    } else {
      validateValue(label, rawValue, errors);
    }
  }

  return errors;
}
