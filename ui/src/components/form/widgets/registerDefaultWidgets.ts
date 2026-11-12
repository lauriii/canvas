import { registerClientWidget } from './registry';
import { booleanCheckboxWidget } from './widgets/BooleanCheckboxWidget';
import {
  dateRangeDefaultWidget,
  dateTimeDefaultWidget,
} from './widgets/DateTimeWidgets';
import { entityReferenceAutocompleteWidget } from './widgets/EntityAutocompleteWidget';
import { fileGenericWidget } from './widgets/FileWidget';
import {
  formattedTextAreaWidget,
  formattedTextFieldWidget,
} from './widgets/FormattedTextWidgets';
import { imageImageWidget } from './widgets/ImageWidget';
import { linkDefaultWidget } from './widgets/LinkWidget';
import { mediaLibraryWidget } from './widgets/MediaLibraryWidget';
import { optionsSelectWidget } from './widgets/OptionsSelectWidget';
import {
  emailDefaultWidget,
  numberWidget,
  stringTextareaWidget,
  stringTextfieldWidget,
} from './widgets/TextInputWidgets';

/**
 * Registers Canvas's own client widgets for the standard Drupal widget set.
 *
 * Runs once at editor boot, before the first form render. After the default
 * registrations a DOM event exposes the registration surface so other in-tree
 * consumers can register additional client widgets or override a default one
 * (e.g. replace the media widget with a DAM-backed one). Registry resolution
 * at render time is synchronous; ids left unregistered simply render via the
 * escape hatch, so late or absent registration can never delay the form.
 */
export const REGISTER_CLIENT_WIDGETS_EVENT = 'canvas:register-client-widgets';

let registered = false;

export function registerDefaultWidgets(): void {
  if (registered) {
    return;
  }
  registered = true;
  registerClientWidget('string_textfield', stringTextfieldWidget);
  registerClientWidget('string_textarea', stringTextareaWidget);
  registerClientWidget('email_default', emailDefaultWidget);
  registerClientWidget('number', numberWidget);
  registerClientWidget('boolean_checkbox', booleanCheckboxWidget);
  registerClientWidget('options_select', optionsSelectWidget);
  registerClientWidget('datetime_default', dateTimeDefaultWidget);
  registerClientWidget('daterange_default', dateRangeDefaultWidget);
  registerClientWidget('link_default', linkDefaultWidget);
  registerClientWidget(
    'entity_reference_autocomplete',
    entityReferenceAutocompleteWidget,
  );
  registerClientWidget('media_library_widget', mediaLibraryWidget);
  registerClientWidget('image_image', imageImageWidget);
  registerClientWidget('file_generic', fileGenericWidget);
  registerClientWidget('text_textarea', formattedTextAreaWidget);
  registerClientWidget('text_textarea_with_summary', formattedTextAreaWidget);
  registerClientWidget('text_textfield', formattedTextFieldWidget);

  document.dispatchEvent(
    new CustomEvent(REGISTER_CLIENT_WIDGETS_EVENT, {
      detail: { registerClientWidget },
    }),
  );
}
