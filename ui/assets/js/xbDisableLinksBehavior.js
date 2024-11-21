/**
 * Drupal behavior for disabling all the links inside the preview in XB
 * @type {Drupal~behavior}
 */
(
  function (Drupal, once) {
    function handleLinkClick() {
      return (ev) => {
        if (ev.currentTarget.target !== '_blank') {
          ev.preventDefault();
          // Send a post message to the parent window with the URL of the clicked link
          window.parent.postMessage(
            { xbPreviewClickedUrl: ev.currentTarget.href },
            '*',
          );
        }
      };
    }

    Drupal.behaviors.xbDisableLinks = {
      attach(context) {
        function bindClick(el) {
          once('xbDisableLinks', el).forEach((element) => {
            element.addEventListener('click', handleLinkClick());
          });
        }

        context.querySelectorAll('a[href]').forEach((el) => {
          bindClick(el);
        });
      },
      detach(context) {
        context.querySelectorAll('a[href]').forEach((el) => {
          el.removeEventListener('click', handleLinkClick());
        });
      },
    };
  }
  // eslint-disable-next-line no-undef
)(Drupal, once);
