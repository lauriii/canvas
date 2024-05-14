/**
 * @file
 * Same Page Preview setting component
 */

// I don't love that we're using jQuery here, but Drupal's ajax system still
// seems to be closely tied to it.
(function (Drupal, once) {
  Drupal.sppSettings = {

    init: context => {
      once('sppSettings', '.spp-settings-wrapper', context).forEach(() => Drupal.sppSettings.createLocalSettingsWidget());
    },

    toggleVisibility: (selector, show) => {
      const element = document.querySelector(selector);
      if (show) {
        element.classList.remove('visually-hidden');
      } else {
        element.classList.add('visually-hidden');
      }
    },

    createLocalSettingsWidget: () => {
      // @see same_page_preview.js
      let settings = Drupal.samePagePreview.settings;

      // Define Markup
      var settingsList = document.createElement('ul');
      settingsList.classList.add('menu', 'spp-menu', 'dropdown-menu-dark');
      // Add the options to the dropdown menu
      for (const [key, setting] of Object.entries(settings)) {
        // Check if the setting is already stored in local storage
        if (localStorage.getItem(setting.storageName) === null) {
          localStorage.setItem(setting.storageName, setting.default);
        }

        // Create checkbox that manages the display of the setting
        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = setting.selector + '-checkbox';
        checkbox.checked = localStorage.getItem(setting.storageName) === '1';

        // Wrap checkbox in a clickable link
        var settingLink = document.createElement('a');
        settingLink.setAttribute('data-drupal-selector', setting.selector);
        settingLink.className = 'spp-settings-dropdown__link';
        settingLink.href = '#';

        var settingLinkLabel = document.createElement('label');
        settingLinkLabel.textContent = setting.label;
        settingLinkLabel.htmlFor = setting.selector + '-checkbox';
        settingLink.appendChild(settingLinkLabel);

        settingLink.insertBefore(checkbox, settingLink.firstChild);

        // Toggle targeted element per listed setting.
        settingLink.addEventListener('click', (event) => {
          event.preventDefault();

          const checkbox = document.querySelector('#' + setting.selector + '-checkbox');
          checkbox.checked = !checkbox.checked;
          localStorage.setItem(setting.storageName, checkbox.checked ? 1 : 0);
          if (setting.target !== undefined) {
            Drupal.sppSettings.toggleVisibility(setting.target, checkbox.checked);
          }
        });

        if (setting.target !== undefined) {
          // Process the initial state of the setting.
          Drupal.sppSettings.toggleVisibility(setting.target, checkbox.checked);
        }

        //Wrap link in a list item. Add item to the list
        var settingItem = document.createElement('li');
        settingItem.className = 'spp-settings-dropdown__item';
        settingItem.appendChild(settingLink);
        settingsList.appendChild(settingItem);
      }

      // Inject markup

      var button_group = document.querySelector('.spp-settings-wrapper');
      button_group.appendChild(settingsList);

      // Events

      var button = document.querySelector('[data-component-id=\'same_page_preview:settings-button\']');
      button.addEventListener('click', (event) => {
        event.preventDefault();
        const menu = document.querySelector('.spp-menu');
        menu.classList.toggle('spp-settings--open');
      });
    },
  }

  Drupal.behaviors.sppSettings = {
    attach: context => {
      Drupal.sppSettings.init(context);
    },
  };

})(Drupal, once);
