import * as field_xbt_comment from './field_xbt_comment.js';
import * as field_xbt_language from './field_xbt_language.js';
import * as field_xbt_options_buttons from './field_xbt_options_buttons.js';
import * as field_xbt_telephone from './field_xbt_telephone.js';
import * as field_xbt_textfield from './field_xbt_textfield.js';
import * as field_xbt_textfield_multi from './field_xbt_textfield_multi.js';
import * as field_xbt_textarea from './field_xbt_textarea.js';
import * as field_xbt_uri from './field_xbt_uri.js';
import * as field_xbt_entity_autocomplete from './field_xbt_entity_autocomplete.js';
import * as field_xbt_daterange_default from './field_xbt_daterange_default.js';
import * as field_xbt_textarea_summary from './field_xbt_textarea_summary.js';
import * as field_xbt_moderation_state from './field_xbt_moderation_state.js';
import * as field_xbt_datetime_timestamp from './field_xbt_datetime_timestamp.js';
import * as field_xbt_daterange_datelist from './field_xbt_daterange_datelist.js';
import * as field_xbt_datetime_datelist from './field_xbt_datetime_datelist.js';
import * as field_xbt_boolean_checkbox from './field_xbt_boolean_checkbox.js';
import * as field_xbt_entity_ref_tags from './field_xbt_entity_ref_tags.js';
import * as field_xbt_int from './field_xbt_integer.js';

// Expand this to add additional coverage.
// For each field to be tested, add a new file that exports two methods as
// follows:
// - 'edit' - The edit method receives the current Cypress instance and
// should perform pre-condition checks (e.g. assert the default state), then
// make an edit to the field.
// - 'assertData' - The assertData method receives the JSON:API representation
// of the entity after the form has been submitted and the entity has been
// published. It should make use of expect to assert the value was correctly
// submitted.
// @see xb_test_article_fields_install for where the fields are created.
export default {
  field_xbt_comment,
  field_xbt_moderation_state,
  field_xbt_language,
  field_xbt_options_buttons,
  field_xbt_telephone,
  field_xbt_textfield,
  field_xbt_textarea,
  field_xbt_uri,
  field_xbt_entity_autocomplete,
  field_xbt_daterange_default,
  field_xbt_textarea_summary,
  field_xbt_datetime_timestamp,
  field_xbt_daterange_datelist,
  field_xbt_datetime_datelist,
  field_xbt_entity_ref_tags,
  field_xbt_boolean_checkbox,
  field_xbt_textfield_multi,
  field_xbt_int,
};
