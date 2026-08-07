import type { BrandKitColorValue } from '@/types/CodeComponent';

/**
 * Converts HSL components (H: 0–360, S: 0–100, L: 0–100) to [r, g, b] each 0–255.
 */
const hslToRgb = (
  h: number,
  s: number,
  l: number,
): [number, number, number] => {
  s /= 100;
  l /= 100;
  const k = (n: number) => (n + h / 30) % 12;
  const a = s * Math.min(l, 1 - l);
  const f = (n: number) =>
    l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)));
  return [
    Math.round(f(0) * 255),
    Math.round(f(8) * 255),
    Math.round(f(4) * 255),
  ];
};

/**
 * Returns the hex string for a BrandKitColorValue.
 * Uses the stored `hex` field when available. Otherwise converts the
 * components to RGB according to the color space before encoding as hex —
 * sRGB components are [0–1], HSL components are [H 0–360, S 0–100, L 0–100].
 */
export const getColorHex = (value: BrandKitColorValue): string => {
  if (value.hex) {
    return value.hex;
  }
  let r: number, g: number, b: number;
  if (value.colorSpace === 'hsl') {
    [r, g, b] = hslToRgb(
      value.components[0],
      value.components[1],
      value.components[2],
    );
  } else {
    // sRGB: components are [0–1]
    r = Math.round(value.components[0] * 255);
    g = Math.round(value.components[1] * 255);
    b = Math.round(value.components[2] * 255);
  }
  return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`;
};

/**
 * Returns the alpha value for a BrandKitColorValue, defaulting to 1.
 */
export const getColorAlpha = (value: BrandKitColorValue): number =>
  value.alpha ?? 1;

/**
 * Converts a BrandKitColorValue to a CSS color string suitable for use in
 * inline styles (e.g. `backgroundColor`). Handles both sRGB and HSL color
 * spaces and incorporates the alpha channel when it is not fully opaque.
 */
export const getCssColorValue = (value: BrandKitColorValue): string => {
  const alpha = getColorAlpha(value);
  // Round to at most 2 decimal places to avoid floating-point artifacts in CSS output.
  const roundedAlpha = Math.round(alpha * 100) / 100;

  switch (value.colorSpace) {
    case 'hsl': {
      const h = Math.round(value.components[0]);
      const s = Math.round(value.components[1]);
      const l = Math.round(value.components[2]);
      return roundedAlpha === 1
        ? `hsl(${h}, ${s}%, ${l}%)`
        : `hsla(${h}, ${s}%, ${l}%, ${roundedAlpha})`;
    }

    case 'srgb':
    default: {
      const hex = getColorHex(value);
      if (roundedAlpha === 1) {
        return hex;
      }
      const cleanHex = hex.replace('#', '');
      const r = parseInt(cleanHex.substring(0, 2), 16);
      const g = parseInt(cleanHex.substring(2, 4), 16);
      const b = parseInt(cleanHex.substring(4, 6), 16);
      return `rgba(${r}, ${g}, ${b}, ${roundedAlpha})`;
    }
  }
};
