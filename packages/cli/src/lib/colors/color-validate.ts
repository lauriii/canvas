import {
  CSS_VARIABLE_PATTERN,
  HEX_COLOR_PATTERN,
} from '@drupal-canvas/discovery';

import type { BrandKitColorFileEntry } from '@drupal-canvas/discovery';

const COLOR_SPACES: ReadonlySet<string> = new Set(['srgb', 'hsl']);
const DISPLAY_FORMATS: ReadonlySet<string> = new Set(['rgb', 'hex', 'hsl']);

/** Server-side pattern for the stored six-digit hex on a token object. */
const STORED_HEX_PATTERN = /^#[0-9a-fA-F]{6}$/;

function entryLabel(entry: BrandKitColorFileEntry, index: number): string {
  const name = typeof entry?.name === 'string' ? entry.name.trim() : '';
  return name !== ''
    ? `Color "${name}" (index ${index})`
    : `Color at index ${index}`;
}

function validateTokenObject(
  label: string,
  value: Record<string, unknown>,
  errors: string[],
): void {
  const colorSpace = value.colorSpace;
  if (typeof colorSpace !== 'string' || !COLOR_SPACES.has(colorSpace)) {
    errors.push(
      `${label}: invalid "value.colorSpace": ${JSON.stringify(colorSpace)}. Expected "srgb" or "hsl".`,
    );
  }
  const components = value.components;
  if (
    !Array.isArray(components) ||
    components.length !== 3 ||
    components.some((c) => typeof c !== 'number' || !Number.isFinite(c))
  ) {
    errors.push(
      `${label}: "value.components" must be an array of exactly 3 numbers.`,
    );
  }
  const alpha = value.alpha;
  if (alpha !== undefined && alpha !== null) {
    if (typeof alpha !== 'number' || alpha < 0 || alpha > 1) {
      errors.push(`${label}: "value.alpha" must be between 0 and 1.`);
    }
  }
  const hex = value.hex;
  if (hex !== undefined && hex !== null) {
    if (typeof hex !== 'string' || !STORED_HEX_PATTERN.test(hex)) {
      errors.push(
        `${label}: invalid "value.hex": ${JSON.stringify(hex)}. Expected a 6-digit hex color like "#cc0000".`,
      );
    }
  }
}

/**
 * Validates the colors array from canvas.brand-kit.json before push: required
 * name, valid CSS custom property name, valid hex string or token object, and
 * no duplicate names or variables. Mirrors the server-side constraints on
 * `canvas.color.*` so failures happen offline, naming the offending entry.
 * Throws with all errors listed so the user can fix the file in one go.
 */
export function validateColorsConfig(colors: BrandKitColorFileEntry[]): void {
  const errors: string[] = [];
  const seenVariables = new Map<string, number>();
  const seenNames = new Map<string, number>();

  for (let i = 0; i < colors.length; i++) {
    const entry = colors[i];
    const label = entryLabel(entry, i);

    if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
      errors.push(`${label}: must be an object.`);
      continue;
    }

    const name = typeof entry.name === 'string' ? entry.name.trim() : '';
    if (name === '') {
      errors.push(`${label}: missing or empty "name".`);
    } else if (seenNames.has(name)) {
      errors.push(
        `${label}: duplicate name "${name}" (also used at index ${seenNames.get(name)}). Color names must be unique.`,
      );
    } else {
      seenNames.set(name, i);
    }

    const cssVariable = entry.cssVariable;
    if (typeof cssVariable !== 'string' || cssVariable.trim() === '') {
      errors.push(`${label}: missing or empty "cssVariable".`);
    } else if (!CSS_VARIABLE_PATTERN.test(cssVariable)) {
      errors.push(
        `${label}: invalid "cssVariable": "${cssVariable}". Expected a CSS custom property name like "--brand-red" (letters, digits, hyphens, and underscores, starting with "--").`,
      );
    } else if (seenVariables.has(cssVariable)) {
      errors.push(
        `${label}: duplicate cssVariable "${cssVariable}" (also used at index ${seenVariables.get(cssVariable)}). CSS variables must be unique.`,
      );
    } else {
      seenVariables.set(cssVariable, i);
    }

    const value = entry.value;
    if (typeof value === 'string') {
      if (!HEX_COLOR_PATTERN.test(value)) {
        errors.push(
          `${label}: invalid "value": "${value}". Expected a hex color like "#cc0000" (or "#cc000080" with alpha), or a color object with "colorSpace" and "components".`,
        );
      }
    } else if (value && typeof value === 'object' && !Array.isArray(value)) {
      validateTokenObject(
        label,
        value as unknown as Record<string, unknown>,
        errors,
      );
    } else {
      errors.push(
        `${label}: missing "value". Expected a hex color string or a color object with "colorSpace" and "components".`,
      );
    }

    const displayFormat = entry.displayFormat;
    if (
      displayFormat !== undefined &&
      displayFormat !== null &&
      (typeof displayFormat !== 'string' || !DISPLAY_FORMATS.has(displayFormat))
    ) {
      errors.push(
        `${label}: invalid "displayFormat": ${JSON.stringify(displayFormat)}. Expected one of: rgb, hex, hsl.`,
      );
    }
  }

  if (errors.length > 0) {
    throw new Error(`Color config validation failed:\n${errors.join('\n')}`);
  }
}
