/**
 * Validates machine name for JS components.
 *
 * @param name - The name to validate.
 * @returns An error message if the name is invalid, or an empty string if it is valid.
 */
export const validateCodeMachineNameClientSide = (name: string) => {
  const cleanedName = name.toLowerCase().replace(/\s+/g, '_');
  if (/^\d/.test(cleanedName)) {
    return Drupal.t('Name cannot start with a number');
  }
  // @see Regex from config/schema/canvas.schema.yml#canvas.js_component.*.
  if (!/^[a-z]([a-zA-Z0-9_-]*[a-zA-Z0-9])*$/.test(cleanedName)) {
    return Drupal.t(
      'Special characters are not allowed. Name cannot start or end with a hyphen, underscore, or whitespace.',
    );
  }
  return '';
};

export const validateFolderNameClientSide = (name: string) => {
  // Trim leading/trailing spaces before validation to allow typing spaces
  // at the end while user is still typing. The final trim happens on submit.
  const trimmedName = name.trim();
  const cleanedName = trimmedName.toLowerCase().replace(/\s+/g, '_');
  if (/^[-_]|[-_]$/.test(cleanedName)) {
    return Drupal.t('Name cannot start or end with a hyphen or underscore.');
  }
  if (/[^a-zA-Z0-9_-]/.test(cleanedName)) {
    return Drupal.t('Special characters are not allowed.');
  }
  return '';
};

/**
 * Validates CSS custom property name.
 *
 * @param value - The value to validate (may or may not include '--' prefix).
 * @returns An error message if invalid, or an empty string if valid.
 */
export const validateCssVariableClientSide = (value: string): string => {
  // Strip leading '--' if present — we accept input with or without it.
  const stripped = value.startsWith('--') ? value.slice(2) : value;
  if (!stripped) {
    return Drupal.t('Variable name cannot be empty.');
  }
  // Must start with a letter, hyphen, or underscore; rest alphanumeric/hyphen/underscore.
  if (!/^[a-zA-Z_-][a-zA-Z0-9_-]*$/.test(stripped)) {
    return Drupal.t(
      'Must be a valid CSS custom property name (letters, numbers, hyphens, underscores only).',
    );
  }
  return '';
};
