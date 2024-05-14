/**
 * @file
 * Same Page Preview javascript.
 */

// I don't love that we're using jQuery here, but Drupal's ajax system still
// seems to be closely tied to it.
(function ($, Drupal, once) {
  // Shared application state for same page preview.
  const samePagePreviewToggle = '#edit-toggle-preview-link',
    samePagePreviewLive = '.node-form .same-page-preview--live-refresh input',
    samePagePreviewForce = '.node-form .same-page-preview--force-refresh',
    samePagePreviewForceElementsOnBlur = 'input, textarea, .ck-editor__editable_inline, .ck-editor__nested-editable',
    samePagePreviewForceElementsOnChange = 'select',
    samePagePreviewCloseBtn = '.same-page-preview-dialog .ui-dialog-titlebar-close',
    samePagePreviewPane = 'iframe.preview',
    defaultPreviewBtn = document.querySelector('[data-drupal-selector="edit-preview"]'),
    previewOnHiddenField = document.querySelector('[data-drupal-selector="edit-ssp-preview-enabled"]'),
    openLink = document.querySelector(samePagePreviewToggle);

  Drupal.samePagePreview = {
    settings:
      {
        'onByDefault': {
          'storageName': 'Drupal.samePagePreview.onByDefault',
          'label': 'Open preview by default',
          'selector': 'edit-toggle-preview-link',
          'default': 1,
        },
        'toggleNewWindow': {
          'storageName': 'Drupal.samePagePreview.toggleNewWindow',
          'label': 'Show New Window button',
          'selector': 'toggle-new-window',
          'target': "[data-drupal-selector='new-window']",
          'default': 1,
        },
        'toggleFullscreen': {
          'storageName': 'Drupal.samePagePreview.toggleFullscreen',
          'label': 'Show Full Screen button',
          'selector': 'toggle-full-screen',
          'target': "[data-drupal-selector='full-screen']",
          'default': 1,
        },
        'toggleViewModes': {
          'storageName': 'Drupal.samePagePreview.toggleViewModes',
          'label': 'Show View Mode drop down',
          'selector': 'toggle-view-mode',
          'target': '.js-form-item-view-mode',
          'default': 1,
        },
      },

    activeState: {
      uuid: null,
      viewMode: 'full',
      previewPaneSrc: null,
      newWindowHref: null,
      scrollPosition: 0,
    },

    init: context => {
      once('samePagePreviewInit', samePagePreviewToggle, context).forEach(element => Drupal.samePagePreview.initializeToggle(element));
      once('samePagePreviewInit', samePagePreviewPane, context).forEach(element => element.addEventListener('load', () => Drupal.samePagePreview.paneRefresh(element)));
      once('samePagePreviewAuto', samePagePreviewLive, context).forEach(element => element.addEventListener('keyup', () => Drupal.samePagePreview.liveRefresh(element)));
      once('samePagePreviewAuto', samePagePreviewForce, context).forEach(element => {
        element.addEventListener('change', (event) => {
          if (event.target.matches(samePagePreviewForceElementsOnChange)) {
            Drupal.samePagePreview.forceRefresh();
          }
        });
        element.addEventListener('blur', (event) => {
          if (event.target.matches(samePagePreviewForceElementsOnBlur)) {
            Drupal.samePagePreview.forceRefresh();
          }
        }, true);
      });
    },

    // Force a refresh of the preview pane.
    forceRefresh: async () => {
      if (defaultPreviewBtn) {
        const iframe = document.querySelector(samePagePreviewPane);
        const iframeWindow = iframe?.contentWindow;
        Drupal.samePagePreview.activeState.scrollPosition = iframeWindow?.document.documentElement.scrollTop;
        const active = document.activeElement;
        await defaultPreviewBtn.dispatchEvent(new Event("click"));
        active.focus();
      }
    },

    liveRefresh: (element) => {
      // If no preview iframe, nothing to do.
      const iframe = document.querySelector('iframe.preview');
      if (!iframe) {
        return;
      }
      let preview = (iframe.contentWindow || iframe.contentDocument);
      if (preview.document) {
        preview = preview.document;
      }
      // @todo target based on a selector in a data attribute instead.
      let title = preview.querySelector('h1');
      if (title) {
        // Some themes nest the text inside a span.
        if (title.querySelector('span')) {
          title = title.querySelector('span');
        }
        title.textContent = element.value;
      } else {
        // If title element isn't created yet, refresh the preview instead.
        Drupal.samePagePreview.forceRefresh();
      }
    },

    initializeToggle: element => {
      // Check for a stored state.
      if (localStorage.getItem('Drupal.samePagePreview.onByDefault') === '1') {
        openLink.dispatchEvent(new Event('click'));
      }

      element.addEventListener('click', () => Drupal.samePagePreview.forceRefresh());
    },

    openDialog: async (element) => {
      // @todo trigger the open more directly, so this will wait for dialog.
      await openLink.dispatchEvent(new Event('click'));
      const active = document.activeElement;
      active.focus();
    },

    closeDialog: async (element) => {
      const closeBtn = document.querySelector(samePagePreviewCloseBtn);
      await closeBtn.dispatchEvent(new Event('click'));
      element.focus();
    },

    paneRefresh: (element) => {
      element.contentWindow.scrollTo({
        top: Drupal.samePagePreview.activeState.scrollPosition,
      });
    },

    /**
     * Update Same Page Preview application state.
     * @param {string} uuid The current node uuid.
     * @param {string} viewMode The view mode to be used for preview.
     */
    updateState: (uuid, viewMode) => {
      const regex = /(\/node\/preview\/)(.*)(\/.*)/;

      // Ensure we have the uuid defined.
      if (uuid) {
        Drupal.samePagePreview.activeState.uuid = uuid;
      } else if (!Drupal.samePagePreview.activeState.uuid) {
        Drupal.samePagePreview.activeState.uuid = document.querySelector('iframe.preview').src.match(regex)[2];
      }

      // Update viewMode if it has changed.
      if (viewMode && (Drupal.samePagePreview.activeState.viewMode !== viewMode)) {
        Drupal.samePagePreview.activeState.viewMode = viewMode;
        Drupal.samePagePreview.activeState.scrollPosition = 0;
      }

      // Rather than just refreshing the preview pane, replacing the iframe src
      // using the provided uuid will allow preview to function in cases where the
      // uuid changes.
      if (!Drupal.samePagePreview.activeState.previewPaneSrc) {
        Drupal.samePagePreview.activeState.previewPaneSrc = document.querySelector(samePagePreviewPane).src;
      }
      Drupal.samePagePreview.activeState.previewPaneSrc = Drupal.samePagePreview.activeState.previewPaneSrc.replace(regex, `$1${Drupal.samePagePreview.activeState.uuid}/${Drupal.samePagePreview.activeState.viewMode}?mode=same_page_preview`);

      // The new window button should also be updated to point to the new uuid.
      if (!Drupal.samePagePreview.activeState.newWindowHref) {
        Drupal.samePagePreview.activeState.newWindowHref = document.querySelector(Drupal.samePagePreview.settings.toggleNewWindow.target + ' a').href;
      }
      Drupal.samePagePreview.activeState.newWindowHref = Drupal.samePagePreview.activeState.newWindowHref.replace(regex, `$1${Drupal.samePagePreview.activeState.uuid}/${Drupal.samePagePreview.activeState.viewMode}`);
    },

    /**
     * Update preview dom elements if the application state has changed.
     * @param {object} previewPane The preview pane iframe.
     * @param {object} newWindowButton The new window button.
     */
    updateDom: (previewPane, newWindowButton) => {
      // Only update form elements if the application state has changed.
      if (Drupal.samePagePreview.activeState.newWindowHref !== newWindowButton.href) {
        newWindowButton.href = Drupal.samePagePreview.activeState.newWindowHref;
      }
      // The preview pane src always updates so that the iframe is reloaded
      previewPane.src = Drupal.samePagePreview.activeState.previewPaneSrc;
    },
  }

  Drupal.behaviors.samePagePreview = {
    attach: context => {
      if (previewOnHiddenField.value === '1') {
        Drupal.samePagePreview.init(context);
      }
    }
  }

  // Public functions.

  /**
   * Re-render the preview pane.
   * @param {string} newUuid The new uuid if the value has changed.
   * @param {string} viewMode The view mode to be used for preview.
   */
  $.fn.samePagePreviewRenderPreview = (newUuid = null, viewMode) => {
    const previewPane = document.querySelector(samePagePreviewPane);
    const newWindowButton = document.querySelector(Drupal.samePagePreview.settings.toggleNewWindow.target);

    if (!previewPane) {
      defaultPreviewBtn.focus();
      Drupal.samePagePreview.openDialog(samePagePreviewToggle);
    } else {
      Drupal.samePagePreview.updateState(newUuid, viewMode);
      Drupal.samePagePreview.updateDom(previewPane, newWindowButton);
    }
  };

  // @ben added everything underneath here


  // Add an outline class and scrll to an element with the name attribute
  // matching `locator`
  const outlineAndScroll = (locator) => {
    const formField = document.querySelector(`[name="${locator}"]`);
    if (formField) {
      formField.classList.add('outline-in-form')
      const y = formField.getBoundingClientRect().top + window.scrollY - 160;
      window.scrollTo({top: y, behavior: 'smooth'});
    }
  }

  // Events sent from the iframe are handled here/
  const managePreviewEvents = (e) =>  {
    const previewWrapper = document.querySelector('#preview-iframe-wrapper');
    const {type, original, additional} = e.detail;

    // When an item is hovered in the iframe, create an element in the primary
    // DOM that is positioned over the iframe to outlines the hovered item.
    if (type === 'itemHoverEnter') {
      const locator = original.target.getAttribute('data-spp-field-locator');
      if (!document.querySelector(`[data-hover-outline="${locator}"]`)) {
        setTimeout(() => {
          // Remove any existing hover outlines.
          document.querySelectorAll('[data-hover-outline], .outline-in-form').forEach((outline) => {
            outline.remove();
          })
          const div = document.createElement('div');
          div.setAttribute('data-hover-outline', locator);

          // Get position info from the iframe element to replicate the
          // positioning of the overlaid div.
          const {x, y} = original.target.getBoundingClientRect();
          const {offsetHeight, offsetWidth} = original.target;
          div.style.left = `${x}px`
          div.style.top = `${y}px`
          div.style.width = `${offsetWidth}px`;
          div.style.height = `${offsetHeight}px`;
          div.style.position = `absolute`;
          div.style.outline = '2px solid #ff69ba';
          previewWrapper.append(div);

          // When the mouse is no longer over an outline. remove it from the DOM.
          div.addEventListener('mouseleave', (e) => {
            document.querySelector(`[name="${locator}"]`)?.classList.remove('outline-in-form')
            div.remove();
          })
          // If the iframe scrolls. remove the outline to avoid it being in a position that
          // no longer corresponds to the element it outlines.
          previewWrapper.querySelector('iframe').contentDocument.addEventListener('scroll', () => {
            document.querySelector(`[name="${locator}"]`)?.classList.remove('outline-in-form')
            div.remove();
          })

          outlineAndScroll(locator);
        }, 30)
      }
    }

    // Given a NodeList of dropzones in the iframe, create DOM dropzones that are
    // positioned directly above them.
    if (type === 'bindZones') {
      // Remove existing dropzones to avoid duplicates.
      document.querySelectorAll('[data-zone-id]').forEach((dropZone) => {
        dropZone.remove();
      })

      // Place a DOM drop zone directly above the iframe drop zone.
      additional.zones.forEach((zone) => {
        const div = document.createElement('div');
        div.setAttribute('data-dom-drop', zone.getAttribute('data-zone-id'));
        const {x, y} = zone.getBoundingClientRect();
        const {offsetHeight, offsetWidth} = zone;

        div.style.left = `${x}px`
        div.style.top = `${y}px`
        div.style.width = `${offsetWidth}px`;
        div.style.height = `${offsetHeight}px`;
        div.style.position = `absolute`;
        div.style.opacity = '0.4';

        // When the dropzone has a valid drop element over it, add the 'can-drop'
        // class.
        div.addEventListener('dragover',  (e) => {
          e.preventDefault();
          e.target.classList.add('can-drop');
        });

        // On drop, parse the dataTransfer data and send it to the iframe. This also
        // includes the drop zone id, which is stored in the 'data-dom-drop' attribute.
        div.addEventListener('drop',  (e) => {
          const data = JSON.parse(unescape(e.dataTransfer.getData("content")));
          const detail = {
            original: e,
            type: 'zoneUpdate',
            additional: {zoneInfo: {zoneId: e.target.getAttribute('data-dom-drop'), ...data}}
          }
          const event = new CustomEvent('parentMessage', { detail })
           document.querySelector('#preview-iframe-wrapper iframe').contentDocument.dispatchEvent(event)
          e.target.classList.remove('can-drop');
        });
        previewWrapper.append(div);
      })
    }
  }

  // When the preview iframe communciates with the DOM, it will do so via a
  // 'previewAction' event. managePreviewEvents will triage the event into the
  // appropriate callback.
  document.addEventListener('previewAction', managePreviewEvents, false)

  // Create a list of draggable items to demonstrate drag/drop.
  // data-drag-content is an object of data that will be sent to the drop
  // callback.
  const ul = document.createElement('ul');
  ul.classList.add('drag-items');
  ul.innerHTML = `
    <li draggable="true" data-drag-content="%7B%22tag%22%3A%22h2%22%2C%22content%22%3A%22This%20is%20content%20that%20we%20shall%20present%20in%20an%20h2%20tag%21%22%7D">Drag H2</li>
    <li draggable="true" data-drag-content="%7B%22tag%22%3A%22h3%22%2C%22content%22%3A%22Howdy%20I%20am%20an%20h3%20how%20do%20you%20do%3F%22%7D">Drag H3</li>
    <li draggable="true" data-drag-content="%7B%22tag%22%3A%22h4%22%2C%22content%22%3A%22And%20dont%20overlook%20the%20h4%2C%20I%20have%20much%20to%20offer%21%22%7D">Drag H4</li>`;

  ul.querySelectorAll('li').forEach((li) => {
    li.addEventListener('dragstart', (e) => {
      // data-drag-content should be transferred to the drop event.
      e.dataTransfer.setData('content', e.target.getAttribute('data-drag-content'))
    })
  });
  document.querySelector('main').append(ul);
})(jQuery, Drupal, once);
