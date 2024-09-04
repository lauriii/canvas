// cspell:ignore ndpoint
const { Drupal } = window as any;

interface JsAttachItem {
  src?: string;
}

/**
 * Takes a response rendered by XBEndpointRenderer, identifies any attached
 * assets, then uses Drupal's AJAX API to add them to the page.
 *
 * This is designed to be used in `transformResponse` setting in endpoints
 * services by createApi such as the one in dummyPropsForm.ts.
 *
 * To use XBEndpointRenderer for a route set the  _wrapper_format option to
 * 'xb_endpoint' in its route definition.
 *
 * @see core/misc/ajax.js
 * @see \Drupal\experience_builder\Render\MainContent\XBEndpointRenderer
 * @see ui/src/services/dummyPropsForm.ts
 */
// @see core/misc/ajax.js
const processResponseAssets = async (response: any, meta: any) => {
  const css =
    meta.response.headers.get('Attach-Css') &&
    JSON.parse(meta.response.headers.get('Attach-Css'));
  const js =
    meta.response.headers.get('Attach-Js') &&
    JSON.parse(meta.response.headers.get('Attach-Js'));
  const settings =
    meta.response.headers.get('Attach-Settings') &&
    JSON.parse(meta.response.headers.get('Attach-Settings'));

  if (css && css.length) {
    try {
      await Drupal.AjaxCommands.prototype['add_css'](
        { instanceIndex: Drupal.ajax.instances.length },
        {
          command: 'add_css',
          status: 'success',
          data: css,
        },
      );
    } catch (e) {
      console.error(e);
    }
  }
  if (js && js.length) {
    try {
      await Drupal.AjaxCommands.prototype['add_js'](
        {
          instanceIndex: Drupal.ajax.instances.length + 1,
          selector: 'head',
        },
        {
          command: 'add_js',
          status: 'success',
          data: js.filter(
            (item: JsAttachItem) =>
              item.src && !document.querySelector(`script[src="${item.src}"]`),
          ),
        },
      );
    } catch (e) {
      console.error(e);
    }
  }
  if (settings && Object.keys(settings).length) {
    try {
      await Drupal.AjaxCommands.prototype['settings'](
        { instanceIndex: Drupal.ajax.instances.length + 2 },
        {
          command: 'settings',
          status: 'success',
          merge: true,
          settings: settings,
        },
      );
    } catch (e) {
      console.error(e);
    }
  }
  return response;
};

export default processResponseAssets;
