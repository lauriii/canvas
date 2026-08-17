/**
 * The sentinel value Drupal's options widgets use for "no value is stored".
 *
 * It is not a value an author can choose: submitting it clears the field, which
 * for a component prop means the prop is dropped from the model entirely and the
 * component falls back to its own default (or renders nothing).
 *
 * @see \Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget::getOptions()
 */
export const EMPTY_OPTION_VALUE = '_none';

/**
 * Label for the sentinel option while the field has no value.
 *
 * Doubles as the closed control's text, so it reads as a state, not an action.
 */
export const EMPTY_OPTION_LABEL = 'No value';

/**
 * Label for the sentinel option while the field has a value.
 *
 * Choosing it is what clears the field, so here it reads as an action. The
 * closed control never shows this label, because picking it makes the field
 * empty and the label reverts to `EMPTY_OPTION_LABEL`.
 */
export const CLEAR_OPTION_LABEL = 'Clear value';

/**
 * Label for the sentinel option on a required field that has no value yet.
 *
 * A required field cannot be cleared, so this prompts for a choice instead of
 * offering one. Drupal drops the option once the field has a value.
 */
export const REQUIRED_EMPTY_OPTION_LABEL = 'Select a value';

/**
 * Picks the label for the sentinel option.
 *
 * @param {boolean} required - Whether the field is required.
 * @param {boolean} isEmpty - Whether the field currently has no value.
 */
export const getEmptyOptionLabel = (
  required: boolean,
  isEmpty: boolean,
): string => {
  if (required) {
    return REQUIRED_EMPTY_OPTION_LABEL;
  }
  return isEmpty ? EMPTY_OPTION_LABEL : CLEAR_OPTION_LABEL;
};
