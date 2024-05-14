/**
 * @file
 * Same Page Preview javascript for settings page.
 */

(function (Drupal, once) {
  Drupal.samePagePreviewAdmin = {
    // Expand this list for additional settings.
    settings: [
      {
        'storageName': 'onByDefault',
        'selector': '#edit-toggle-preview-link',
      },
    ],

    init: () => {
      Drupal.samePagePreviewAdmin.settings.forEach((setting) => Drupal.samePagePreviewAdmin.getPersonalizedSetting(setting));
    },

    // All personalization settings are expected to be checkboxes.
    getPersonalizedSetting: (setting) => {
      const prefix = 'samePagePreview--';
      const element = document.querySelector(setting.selector);
      element.checked = localStorage.getItem(prefix + setting.storageName) === '1';
      element.addEventListener('change', () => localStorage.setItem(prefix + setting.storageName, element.checked ? 1 : 0));
    }
  }

  Drupal.behaviors.samePagePreviewAdmin = {
    attach: function (context) {
      once('samePagePreviewAdmin', context).forEach(() => Drupal.samePagePreviewAdmin.init());
    }
  };
})(Drupal, once);

