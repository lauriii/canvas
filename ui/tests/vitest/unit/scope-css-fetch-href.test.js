// Regression coverage for issue #3580207: scopeCss() must not mangle absolute
// stylesheet URLs (protocol-relative CDN URLs such as
// `//cdn.jsdelivr.net/...`) into broken same-origin URLs when `processPaths` is
// set. This loads the real, shipped `js/ajax.command.customizations.js` source
// (rather than re-implementing the logic) and asserts on the URL that scopeCss
// hands to `fetch`.

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import {
  afterEach,
  beforeAll,
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from 'vitest';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const moduleRoot = path.resolve(__dirname, '../../../..');

/**
 * Runs scopeCss for a given stylesheet href and returns the URL it fetched.
 *
 * @param {object} styleSheetData
 *   The stylesheet data passed to scope_css.
 * @return {Promise<string|undefined>}
 *   The URL passed to fetch, or undefined if fetch was never called.
 */
const getFetchedHref = async (styleSheetData) => {
  let fetchedUrl;
  const fetchMock = vi.fn((input) => {
    fetchedUrl = typeof input === 'string' ? input : input?.url;
    // Return an empty stylesheet so scopeCss can proceed past the fetch.
    return Promise.resolve(new Response('', { status: 200 }));
  });
  vi.stubGlobal('fetch', fetchMock);

  try {
    // Downstream CSSOM work (constructable stylesheets, @scope) is not the
    // subject under test and may be unsupported by jsdom, so swallow anything
    // thrown after the fetch has already happened.
    await window.Drupal.AjaxCommands.prototype.scope_css(
      styleSheetData,
      '.scope',
    );
  } catch {
    // Intentionally ignored — we only care about the fetched URL.
  }

  return fetchedUrl;
};

describe('scopeCss fetch href resolution (issue #3580207)', () => {
  beforeAll(() => {
    // The shipped code expects these to exist as globals.
    global.jQuery = () => ({});
    global.Drupal = {
      behaviors: {},
      AjaxCommands: function AjaxCommands() {},
      Ajax: function Ajax() {},
    };
    global.Drupal.AjaxCommands.prototype = {};
    global.Drupal.Ajax.prototype = {};
    global.drupalSettings = { path: { baseUrl: '/' }, canvas: {} };

    // Load the vendored css-tree UMD build, exposing the `csstree` global that
    // the customizations file closes over.
    const csstreeSrc = fs.readFileSync(
      path.join(moduleRoot, 'js/assets/css-tree.js'),
      'utf8',
    );
    // Indirect eval runs in global scope so `var csstree` becomes a global.
    (0, eval)(csstreeSrc);

    // Load the real customizations source, which registers scope_css via its
    // Drupal behavior.
    const iifeSrc = fs.readFileSync(
      path.join(moduleRoot, 'js/ajax.command.customizations.js'),
      'utf8',
    );
    (0, eval)(iifeSrc);
    global.Drupal.behaviors.enhanceAddCssForDialogsUsingAdminTheme.attach();
  });

  beforeEach(() => {
    // scopeCss de-dupes by the presence of a prior style element for the href,
    // so clear any residue between assertions.
    document
      .querySelectorAll('[data-dialog-style-from]')
      .forEach((node) => node.remove());
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('leaves a protocol-relative CDN URL untouched when processPaths is set', async () => {
    const href =
      '//cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css';
    expect(await getFetchedHref({ href, processPaths: true })).toBe(href);
  });

  it('leaves a fully-qualified https URL untouched when processPaths is set', async () => {
    const href =
      'https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.min.css';
    expect(await getFetchedHref({ href, processPaths: true })).toBe(href);
  });

  it('still resolves a root-relative URL against origin and baseUrl', async () => {
    const href = '/core/misc/components/progress.module.css';
    expect(await getFetchedHref({ href, processPaths: true })).toBe(
      `${window.location.origin}/core/misc/components/progress.module.css`,
    );
  });
});
