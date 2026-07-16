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
 * The machine-name prefix for the `component_tree` field backing a new slot.
 *
 * @see \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::FIELD_NAME_PREFIX
 */
export const SLOT_FIELD_PREFIX = 'canvas_slot_';

/** Drupal caps field machine names at 32 characters. */
const MAX_FIELD_NAME_LENGTH = 32;
const MAX_SLOT_FIELD_SUFFIX_LENGTH =
  MAX_FIELD_NAME_LENGTH - SLOT_FIELD_PREFIX.length;

/**
 * Derives a `canvas_slot_`-prefixed field machine name from a slot label.
 *
 * An exposed slot IS a `component_tree` field; the field machine name is the
 * slot's stable identity. Mirrors Drupal's transform (lowercase, non-alphanumeric
 * runs collapsed to underscore) within the 32-char field-name budget.
 *
 * @param label - The human-readable slot label.
 * @returns The derived field machine name, or '' if no valid suffix remains.
 */
export const deriveSlotFieldName = (label: string): string => {
  const suffix = label
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+/g, '')
    .slice(0, MAX_SLOT_FIELD_SUFFIX_LENGTH)
    .replace(/_+$/g, '');
  return suffix ? `${SLOT_FIELD_PREFIX}${suffix}` : '';
};

/** Drupal field machine names: start with a letter, [a-z0-9_], no trailing underscore. */
const FIELD_NAME_PATTERN = /^[a-z][a-z0-9_]*[a-z0-9]$/;

/**
 * Validates a slot field machine name (the "create new slot" path).
 *
 * @param name - The machine name to validate.
 * @param existingNames - Names already used in this template (for uniqueness).
 * @returns An error message if invalid, or an empty string if valid.
 */
export const validateSlotFieldName = (
  name: string,
  existingNames: string[] = [],
) => {
  const trimmed = name.trim();
  if (!trimmed) {
    return 'Machine name is required.';
  }
  if (!trimmed.startsWith(SLOT_FIELD_PREFIX)) {
    return `Machine name must start with "${SLOT_FIELD_PREFIX}".`;
  }
  if (trimmed.length > MAX_FIELD_NAME_LENGTH) {
    return `Machine name may be at most ${MAX_FIELD_NAME_LENGTH} characters.`;
  }
  if (!FIELD_NAME_PATTERN.test(trimmed)) {
    return 'Machine name may only contain lowercase letters, numbers and underscores, and cannot end with an underscore.';
  }
  if (existingNames.includes(trimmed)) {
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
