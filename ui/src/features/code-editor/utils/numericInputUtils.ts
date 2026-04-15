/**
 * Shared validation utilities for Integer and Number prop inputs.
 */

/**
 * Returns a validation error message for a numeric string produced by a
 * native number input, or null when the value is valid or empty.
 */
export function getNumericInputError(
  value: string,
  type: 'integer' | 'number',
): string | null {
  if (!value) return null;
  if (type === 'integer') {
    if (value.includes('.')) return 'Integers cannot have decimal values.';
    if (!/^[+-]?\d+$/.test(value)) return 'Please enter a valid integer.';
  }
  if (type === 'number') {
    if (!/^[+-]?(\d+\.?\d*|\.\d+)$/.test(value))
      return 'Please enter a valid number.';
  }
  return null;
}
