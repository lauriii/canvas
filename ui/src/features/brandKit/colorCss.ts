import { getCssColorValue } from '@/utils/brandKitColor';

import type { BrandKitColor } from '@/types/CodeComponent';

/**
 * Builds a CSS custom property for a single color.
 *
 * @param color - The color to build the property for.
 * @returns The CSS property string (e.g., "  --color-name: #hex;").
 */
const buildColorProperty = (color: BrandKitColor): string => {
  return `  ${color.cssVariable}: ${getCssColorValue(color.value)};`;
};

/**
 * Sorts colors by weight, then alphabetically by name.
 *
 * @param colors - The colors to sort.
 * @returns The sorted colors.
 */
const sortColors = (colors: BrandKitColor[]): BrandKitColor[] => {
  return [...colors].sort((a, b) => {
    // Sort by weight first.
    const weightDiff = a.weight - b.weight;
    if (weightDiff !== 0) {
      return weightDiff;
    }
    // Then by name alphabetically (case-insensitive).
    return a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
  });
};

/**
 * Builds CSS custom properties for Brand Kit colors.
 *
 * Generates a :root block containing CSS variables for each color,
 * sorted by weight then alphabetically by name.
 *
 * @param colors - The colors to build styles for.
 * @returns The CSS string, or empty string if no colors.
 */
export const buildColorStyles = (
  colors: BrandKitColor[] | null | undefined,
): string => {
  if (!colors || colors.length === 0) {
    return '';
  }

  const sortedColors = sortColors(colors);
  const properties = sortedColors.map(buildColorProperty);

  return `:root {\n${properties.join('\n')}\n}`;
};
