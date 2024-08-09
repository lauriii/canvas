/**
 * @file
 * Same Page Preview full screen component
 */

// I don't love that we're using jQuery here, but Drupal's ajax system still
// seems to be closely tied to it.
(function ($, Drupal, once) {
  const setting= {
      'storageName': 'Drupal.samePagePreview.toggleFullscreen',
      'label': 'Show Full Screen button',
      'selector': 'toggle-full-screen',
      'target': "[data-drupal-selector='full-screen']",
      'default': 1,
    },
    offCanvasDialogSelector = '.same-page-preview-dialog #drupal-off-canvas'
  ;

  Drupal.sppFullScreen = {
    init: context => {
      once('sppFullScreen', setting.target, context).forEach(element => Drupal.sppFullScreen.initFullScreenButton());
    },
    initFullScreenButton: () => {
      // Handle null case
      if (localStorage.getItem(setting.storageName) === null) {
        localStorage.setItem(setting.storageName, 1);
      }
      var fullscreenButton = document.querySelector(setting.target);
      if(!fullscreenButton.classList.contains('on') && !fullscreenButton.classList.contains('off')) {
        fullscreenButton.classList.add('off');
      }
      fullscreenButton.addEventListener('click', (event) => {
        Drupal.sppFullScreen.toggleFullscreen(event);
      });

      // Turn off the ability to resize the dialog,
      var dialog = $(offCanvasDialogSelector);
      dialog.dialog('option', 'resizable', false);
    },
    toggleFullscreen: (event) => {
      event.preventDefault();
      var element = event.currentTarget;
      var dialog = $(offCanvasDialogSelector);

      // If currently active, deactivate.  If inactive, activate.

      if (element.classList.contains('on')) {
        // Deactivate
        element.classList.remove('on');
        element.classList.add('off');
        element.querySelector('span').innerText = 'Full Screen';
        dialog.dialog('option', 'width', '50%');
      }
      else {
        // Activate
        element.classList.remove('off');
        element.classList.add('on');
        element.querySelector('span').innerText = 'Split Screen';
        dialog.dialog('option', 'width', '100%');
      }
    },
  }

  Drupal.behaviors.sppFullScreen = {
    attach: context => {
      Drupal.sppFullScreen.init(context);
    },
  };

})(jQuery, Drupal, once);
