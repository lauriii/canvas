/**
 * Supported font file extensions, in the order of preference.
 */
export const FONT_EXTENSIONS = ['woff2', 'woff', 'ttf', 'otf'] as const;

export type FontExtension = (typeof FONT_EXTENSIONS)[number];

/**
 * Normalizes a font format string to a base extension.
 *
 * Handles variable font formats like "woff2-variations" -> "woff2".
 */
export function normalizeFontFormat(format: string | undefined): string | null {
  if (!format) return null;
  const normalized = format.trim().toLowerCase();
  // Check if it starts with any of our supported extensions
  for (const ext of FONT_EXTENSIONS) {
    if (normalized === ext || normalized.startsWith(`${ext}-`)) {
      return ext;
    }
  }
  return null;
}
