/**
 * @file
 * Extends autocomplete for use in Experience Builder.
 */
(function ($, Drupal) {
  Drupal.behaviors.autocompleteXbExtend = {
    attach(context) {
      // Act on the same textfields that receive autocomplete functionality.
      // @see core/misc/autocomplete.js
      once('autocomplete-xb', 'input.form-autocomplete', context).forEach(
        (element) => {
          const $element = $(element);

          // The logic below exists so we don't need to use jQuery or
          // addEventListener directly inside an XB React component.
          // The avoidance of jQuery inside is partially due to circumstantial
          // but inconclusive evidence suggesting it reduced the stability of
          // e2e tests. It is also done this way to make things more
          // maintainable - familiarity with jQuery should not be necessary to
          // work on XB React code.
          //
          // By listening to the jQuery-specific events here and converting them
          // to events that can be received by on* attributes, the React input
          // components are able to handle the autocomplete events the same way
          // change and blur events are handled.
          //
          // To do this, we translate jQuery events to E6 ones. To receive them via
          // on* attributes, we must use native JS events. The pause, play and
          // ended events are used as they are listenable via on* events, but
          // never actually used on text inputs, which ensures that native
          // functionality is not disrupted.
          $element.on('autocompletesearch.autocomplete', () => {
            // The 'pause' event is used as the autocompletesearch event should
            // pause real time preview updates on value change.
            element.dispatchEvent(new Event('pause'));
          });

          $element.on('autocompleteselect.autocomplete', function (e, ui) {
            // Add the additional `ui` argument to the event detail.
            const selectToPlay = new CustomEvent('play', {
              detail: {
                ui,
              },
            });

            // The 'play' event is used as the autocompleteselect event should
            // result in real time previews being un-paused.
            element.dispatchEvent(selectToPlay);
          });

          // Although blur is not jQuery specific, XB React inputs already have
          // onBlur listeners, so we add additional blur handling here by
          // converting it to an 'ended' event.
          $element.on('blur.autocomplete', (e) => {
            // The 'ended' event is used since it occurs when focus has ended,
            // making the association at least slightly intuitive.
            element.dispatchEvent(new Event('ended'));
          });
        },
      );
    },
  };
})(jQuery, Drupal);
