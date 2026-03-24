/**
 * @file
 * Extends autocomplete for use in Drupal Canvas.
 */

(function ($, Drupal) {
  Drupal.autocomplete.options.position = {collision: 'flip'};

  Drupal.behaviors.autocompleteCanvasExtend = {
    attach(context) {
      // Act on the same textfields that receive autocomplete functionality.
      // @see core/misc/autocomplete.js
      once('autocomplete-canvas', 'input.form-autocomplete', context).forEach(
        (element) => {
          const $element = $(element);
          $element.on('autocompleteselect.autocomplete', function (e, ui) {
            // Remove the attribute that prevents updating the store and preview
            // as we are now selecting the value we want, not entering a search
            // string.
            e.target.removeAttribute('data-canvas-no-update');

            // Process the selection with Drupal core's logic.
            Drupal.autocomplete.options.select(e, ui)

            // Set the attribute and dispatch a custom event so React components
            // can listen directly instead of relying on a MutationObserver.
            e.target.setAttribute('data-canvas-autocomplete-selected', e.target.value);
            e.target.dispatchEvent(new CustomEvent('data-canvas-autocomplete-selected', { bubbles: true, detail: { value: ui.item.value } }));
          });

          $element.on('autocompleteresponse.autocomplete', function (e, ui) {
            if (ui.content && ui.content.length > 0) {
              // If autocomplete suggestions are available, set an attribute
              // that temporarily prevents updating the preview and store as the
              // input is performing a search - not yet specifying the desired
              // value.
              e.target.setAttribute('data-canvas-no-update', 'true');
            } else {
              // If no autocomplete suggestions are available, unset the
              // attribute so it updates the preview and store like any text
              // input would.
              e.target.removeAttribute('data-canvas-no-update');
            }
          });
        },
      );
    },
  };
})(jQuery, Drupal);
