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
    // Keys that would reach or replace an object's prototype.
    if (['__proto__', 'constructor', 'prototype'].includes(key)) {
      return;
    }
    const current = target[key];
    if (isObject(value) && isObject(current)) {
      mergeSettings(current, value);
    } else {
      // A copy: values from responses and the store are frozen, and later
      // merges must be able to change them.
      target[key] = structuredClone(value);
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

// The URL of a stylesheet or script, to tell whether a document has it.
const assetUrl = (attributes: Record<string, string | boolean>) =>
  String(attributes.href ?? attributes.src ?? '');

const hasAsset = (
  document: Document,
  attributes: Record<string, string | boolean>,
) =>
  [
    ...document.querySelectorAll<HTMLLinkElement | HTMLScriptElement>(
      'link[href], script[src]',
    ),
  ].some(
    (element) =>
      element.getAttribute('href') === assetUrl(attributes) ||
      element.getAttribute('src') === assetUrl(attributes),
  );

// Long enough for a slow asset, short enough not to stop later responses.
const ASSET_TIMEOUT = 10000;

// Adds an asset the document lacks. Resolves once it loads, fails, will not
// load, or takes longer than ASSET_TIMEOUT.
const addElement = (
  parent: HTMLElement,
  tagName: 'link' | 'script',
  attributes: Record<string, string | boolean>,
) =>
  new Promise<void>((resolve) => {
    const { ownerDocument } = parent;
    if (hasAsset(ownerDocument, attributes)) {
      resolve();
      return;
    }
    const element = createElement(ownerDocument, tagName, attributes);
    element.addEventListener('load', () => resolve());
    element.addEventListener('error', () => resolve());
    setTimeout(resolve, ASSET_TIMEOUT);
    parent.appendChild(element);
    // Browsers that support modules skip nomodule scripts without an event.
    if (tagName === 'script' && attributes.nomodule) {
      resolve();
    }
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
    getActivePreviewWindows().map((window) => loadAssetsInto(window, assets)),
  );

/**
 * Loads assets into one preview window, like loadPreviewAssets().
 */
export const loadAssetsInto = async (window: Window, assets: PreviewAssets) => {
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
  // New scripts may define behaviors for markup the preview already has,
  // for example when only a prop changed which libraries are attached.
  // once() keeps other behaviors from attaching twice.
  if (assets.jsHeader.length || assets.jsFooter.length) {
    (
      window as unknown as {
        Drupal?: { attachBehaviors: (context: Document) => void };
      }
    ).Drupal?.attachBehaviors(window.document);
  }
};

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
  const add = (
    parent: HTMLElement,
    tagName: 'link' | 'script',
    attributes: Record<string, string | boolean>,
  ) => {
    if (!hasAsset(document, attributes)) {
      parent.append(createElement(document, tagName, attributes));
    }
  };
  assetsList.forEach((assets) => {
    mergeSettings(settings, assets.settings);
    assets.css.forEach((attributes) => add(document.head, 'link', attributes));
    // Added header scripts go after the page's own scripts too: they may
    // depend on libraries the page loads in its footer.
    [...assets.jsHeader, ...assets.jsFooter].forEach((attributes) =>
      add(document.body, 'script', attributes),
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
