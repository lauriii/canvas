/**
 * Drupal behavior for rendering human-readable date time from ISO date strings.
 * @type {Drupal~behavior}
 */
(
  function (Drupal, once) {
    Drupal.behaviors.canvasTestSdcDate = {
      attach(context) {
        const timeEl = context.querySelector('time[datetime]');
        const isoDate = timeEl.getAttribute('datetime');
        // @todo The date can be omitted for now, but remove this in https://www.drupal.org/i/3530808.
        if (isoDate === '') {
          timeEl.textContent = '🗓️';
          return;
        }

        // Uses Intl.DateTimeFormat for locale-aware formatting.
        // - MDN: https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Intl/DateTimeFormat
        // - ECMAScript Intl API: https://tc39.es/ecma402/#datetimeformat-objects
        // Reads the ISO date from the datetime attribute and formats it for the
        // user's locale.
        const date = new Date(isoDate);
        timeEl.textContent = new Intl.DateTimeFormat(navigator.language, {
          year: 'numeric',
          month: 'long',
          day: 'numeric'
        }).format(date);
      },
      detach(context) {
        //  No-op.
      },
    };
  }
  // eslint-disable-next-line no-undef
)(Drupal, once);
