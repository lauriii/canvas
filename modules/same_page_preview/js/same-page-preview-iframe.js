// @ben made this whole file.
// This file is loaded in preview iframes only.

// Defines two dropzones,
// - `selector`: selectes the element it appends or prepends to
// - `attach`: Where the zone appears in relation to the selector,
//             either 'append' or 'prepend'
// - `dropTo`: When dropped, where does the dropped content appear relative to
//             the drop zone. Either 'before' or 'after'
const zones = [
  {
    selector: 'main',
    attach: 'prepend',
    dropTo: 'after',
  },
  {
    selector: 'main',
    attach: 'append',
    dropTo: 'before',
  }
];



((Drupal) => {
  /**
   * Sends an event to the primary DOM
   * @param type
   *   The custom event type
   * @param original
   *   The original event (click, mouseenter, etc)
   * @param additional
   *   An optional object of additional data sent with the event.
   */
  const windowEventSend = (type, original, additional = {}) => {
    const detail = {
      type,
      original,
      additional,
    }

    const event = new CustomEvent('previewAction', { detail })
    window.parent.document.dispatchEvent(event)
  }


  // Informs the primary DOM of the drop zones present.
  const bindZones = () => {
    const allZones = document.querySelectorAll('[data-zone-id]');
    windowEventSend('bindZones', new CustomEvent('noop'), {zones: allZones});
  }

  // Adds content above/below a drop zone.
  const updateDropZone = ({zoneId, tag, content}) => {
    const zone = document.querySelector(`[data-zone-id="${zoneId}"]`);
    const dropTo = zone.getAttribute('data-zone-drop-to');
    const el = document.createElement(tag);
    el.textContent = content;
    zone[dropTo](el);
    bindZones();
  }


  // Receives messages from the primary DOM and triages them based on the
  // event type.
  document.addEventListener('parentMessage', (e) => {
    const {type, original, additional} = e.detail;
    if (type === 'zoneUpdate') {
      updateDropZone(additional.zoneInfo);
    }
  })

  // Every item that is associated with a field will send messages to the DOM
  // on mouseenter and mouseleave. This is used to create primary-DOM outlines of
  // the iframed content regions.
  document.querySelectorAll('[data-spp-field-locator]').forEach((item) => {
    item.addEventListener('mouseenter', (e) => {
      windowEventSend('itemHoverEnter', e);
    })
    item.addEventListener('mouseleave', (e) => {
      windowEventSend('itemHoverLeave', e);
    })
  })

  // Create drop zone elements based on the definitions in `zones`.
  zones.forEach((zone, index) => {
    const div = document.createElement('div');
    div.setAttribute('data-zone-id', index)
    div.setAttribute('data-zone-drop-to', zone.dropTo)
    div.style.height = '80px';
    div.style.width = '100%';
    div.style.border = '3px dashed black';
    div.style.backgroundColor = '#e7e7e7';
    document.querySelector(zone.selector)[zone.attach](div)
  })
  // Calling bindZones communicates the presence of the drop zones to the
  // primary DOM so it can create DOM drop zones directly above them.
  bindZones();
})(Drupal);


