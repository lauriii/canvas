/**
 * Validates machine name for JS components.
 *
 * @param name - The name to validate.
 * @returns An error message if the name is invalid, or an empty string if it is valid.
 */
export const validateCodeMachineNameClientSide = (name: string) => {
  const cleanedName = name.toLowerCase().replace(/\s+/g, '_');
  if (/^\d/.test(cleanedName)) {
    return 'Name cannot start with a number';
  }
  // @see Regex from config/schema/canvas.schema.yml#canvas.js_component.*.
  if (!/^[a-z]([a-zA-Z0-9_-]*[a-zA-Z0-9])*$/.test(cleanedName)) {
    return 'Special characters are not allowed. Name cannot start or end with a hyphen, underscore, or whitespace.';
  }
  return '';
};

/**
 * Derives a machine-name alias from a human-readable exposed-slot label.
 *
 * Mirrors Drupal's machine-name transform: lowercase, non-alphanumeric runs
 * collapsed to a single underscore, with leading/trailing underscores trimmed.
 *
 * @param label - The human-readable label.
 * @returns The derived machine-name alias.
 */
export const deriveExposedSlotAlias = (label: string): string =>
  label
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');

/**
 * The alias pattern enforced server-side for exposed slots.
 *
 * @see config/schema/canvas.schema.yml (SequenceKeysMatchRegex on exposed_slots)
 */
const EXPOSED_SLOT_ALIAS_PATTERN = /^[a-z0-9]+([a-z0-9_-]+)[a-z0-9]+$/;

/**
 * Validates an exposed-slot machine-name alias.
 *
 * @param alias - The alias to validate.
 * @param existingAliases - Aliases already used in this template (for uniqueness).
 * @returns An error message if invalid, or an empty string if valid.
 */
export const validateExposedSlotAlias = (
  alias: string,
  existingAliases: string[] = [],
) => {
  if (!alias.trim()) {
    return 'Machine name is required.';
  }
  if (!EXPOSED_SLOT_ALIAS_PATTERN.test(alias)) {
    return 'Machine name may only contain lowercase letters, numbers, underscores and hyphens, must be at least 3 characters long, and cannot start or end with an underscore or hyphen.';
  }
  if (existingAliases.includes(alias)) {
    return 'This machine name is already in use in this template.';
  }
  return '';
};

export const validateFolderNameClientSide = (name: string) => {
  // Trim leading/trailing spaces before validation to allow typing spaces
  // at the end while user is still typing. The final trim happens on submit.
  const trimmedName = name.trim();
  const cleanedName = trimmedName.toLowerCase().replace(/\s+/g, '_');
  if (/^[-_]|[-_]$/.test(cleanedName)) {
    return 'Name cannot start or end with a hyphen or underscore.';
  }
  if (/[^a-zA-Z0-9_-]/.test(cleanedName)) {
    return 'Special characters are not allowed.';
  }
  return '';
};
