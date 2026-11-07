((Drupal, once, drupalSettings) => {
  Drupal.behaviors.canvasTestHighlight = {
    attach(context) {
      once('canvas-test-highlight', '.canvas-test-highlight', context).forEach(
        (element) => {
          element.dataset.highlightColor =
            drupalSettings.canvasTestHighlight.color;
        },
      );
    },
  };
})(Drupal, once, drupalSettings);
