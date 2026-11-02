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

const createElement = (
  document: Document,
  tagName: 'link' | 'script',
  attributes: Record<string, string | boolean>,
) => {
  const element = document.createElement(tagName);
  Object.entries(attributes).forEach(([name, value]) => {
    if (value !== false) {
      element.setAttribute(name, value === true ? '' : value);
    }
  });
  return element;
};

const addElement = (
  parent: HTMLElement,
  tagName: 'link' | 'script',
  attributes: Record<string, string | boolean>,
) =>
  new Promise<void>((resolve) => {
    const element = createElement(parent.ownerDocument, tagName, attributes);
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

/**
 * Adds assets to a preview document's HTML, for a frame created from it.
 */
export const injectPreviewAssets = (
  html: string,
  assetsList: PreviewAssets[],
): string => {
  if (!assetsList.length) {
    return html;
  }
  const document = new DOMParser().parseFromString(html, 'text/html');
  const settingsElement = document.querySelector(
    'script[data-drupal-selector="drupal-settings-json"]',
  );
  const settings = JSON.parse(settingsElement?.textContent || '{}');
  assetsList.forEach((assets) => {
    mergeSettings(settings, assets.settings);
    assets.css.forEach((attributes) =>
      document.head.append(createElement(document, 'link', attributes)),
    );
    assets.jsHeader.forEach((attributes) =>
      document.head.append(createElement(document, 'script', attributes)),
    );
    assets.jsFooter.forEach((attributes) =>
      document.body.append(createElement(document, 'script', attributes)),
    );
  });
  if (settingsElement) {
    // Escape like Drupal's Json::encode(), so no value can end the element.
    // These characters only occur inside JSON strings.
    settingsElement.textContent = JSON.stringify(settings).replace(
      /[<>&']/g,
      (character) =>
        `\\u${character.charCodeAt(0).toString(16).padStart(4, '0')}`,
    );
  }
  const doctype = document.doctype ? `<!DOCTYPE ${document.doctype.name}>` : '';
  return doctype + document.documentElement.outerHTML;
};
