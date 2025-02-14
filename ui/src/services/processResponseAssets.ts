import type { PropsValues } from '@/types/Form';

const { Drupal } = window as any;

/**
 * Takes a response rendered by XBTemplateRenderer, identifies any attached
 * assets, then uses Drupal's AJAX API to add them to the page.
 *
 * This is designed to be used in `transformResponse` setting in endpoints
 * services by createApi such as the one in dummyPropsForm.ts.
 *
 * To use XBTemplateRenderer for a route set the  _wrapper_format option to
 * 'xb_template' in its route definition.
 *
 * @see core/misc/ajax.js
 * @see \Drupal\experience_builder\Render\MainContent\XBTemplateRenderer
 * @see ui/src/services/dummyPropsForm.ts
 */
// @see core/misc/ajax.js
const processResponseAssets = async (response: any, meta: any) => {
  const { css, js, settings } = response;

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
  if (js && Object.values(js).length) {
    // Although ajax_page_state does a good job preventing assets from
    // reloading, there are race conditions that can result in assets being
    // requested despite already being present, and this check prevents the
    // duplicate addition from occurring.
    const jsToAdd = Object.values(js as PropsValues[]).filter(
      (asset) => !document.querySelector(`script[src="${asset.src}"]`),
    );
    try {
      jsToAdd.length &&
        (await Drupal.AjaxCommands.prototype['add_js'](
          {
            instanceIndex: Drupal.ajax.instances.length + 1,
            selector: 'head',
          },
          {
            command: 'add_js',
            status: 'success',
            data: jsToAdd,
          },
        ));
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

  return response.html;
};

export default processResponseAssets;
