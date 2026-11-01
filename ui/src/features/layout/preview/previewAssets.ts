/**
 * Assets and settings a partial preview needs and the preview page lacks.
 *
 * @see \Drupal\canvas\Controller\ApiLayoutController::buildMissingAssets()
 */
export interface PreviewAssets {
  css: Record<string, string | boolean>[];
  jsHeader: Record<string, string | boolean>[];
  jsFooter: Record<string, string | boolean>[];
  settings: Record<string, unknown>;
}

// The preview's own drupalSettings, unlike the editor's typed global.
type PreviewGlobals = { drupalSettings?: Record<string, unknown> };

const getActivePreviewWindows = () =>
  [
    ...document.querySelectorAll<HTMLIFrameElement>(
      'iframe[data-canvas-swap-active="true"]',
    ),
  ]
    .map((iframe) => iframe.contentWindow)
    .filter((window): window is Window => !!window?.document);

const getSettings = (window: Window) =>
  ((window as unknown as PreviewGlobals).drupalSettings ??= {});

/**
 * Returns the libraries the preview page has loaded, as Drupal lists them in
 * `drupalSettings.ajaxPageState.libraries`.
 */
export const getPreviewLibraries = (): string | undefined =>
  (
    (getActivePreviewWindows()[0] as unknown as PreviewGlobals | undefined)
      ?.drupalSettings?.ajaxPageState as { libraries?: string } | undefined
  )?.libraries;

const isObject = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

const mergeSettings = (
  target: Record<string, unknown>,
  source: Record<string, unknown>,
) => {
  Object.entries(source).forEach(([key, value]) => {
    const current = target[key];
    if (isObject(value) && isObject(current)) {
      mergeSettings(current, value);
    } else {
      target[key] = value;
    }
  });
};

const addElement = (
  parent: HTMLElement,
  tagName: 'link' | 'script',
  attributes: Record<string, string | boolean>,
) =>
  new Promise<void>((resolve) => {
    const element = parent.ownerDocument.createElement(tagName);
    Object.entries(attributes).forEach(([name, value]) => {
      if (value !== false) {
        element.setAttribute(name, value === true ? '' : value);
      }
    });
    element.addEventListener('load', () => resolve());
    element.addEventListener('error', () => resolve());
    parent.appendChild(element);
  });

/**
 * Loads assets into every active preview iframe, like the `add_css`,
 * `add_js` and `settings` commands of Drupal's AJAX responses.
 *
 * Settings are merged first and scripts load in order, so the markup that
 * needs them can be added once this resolves.
 */
export const loadPreviewAssets = (assets: PreviewAssets) =>
  Promise.all(
    getActivePreviewWindows().map(async (window) => {
      const { head, body } = window.document;
      mergeSettings(getSettings(window), assets.settings);
      await Promise.all(
        assets.css.map((attributes) => addElement(head, 'link', attributes)),
      );
      for (const attributes of assets.jsHeader) {
        await addElement(head, 'script', attributes);
      }
      for (const attributes of assets.jsFooter) {
        await addElement(body, 'script', attributes);
      }
    }),
  );
