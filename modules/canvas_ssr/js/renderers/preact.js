/**
 * The framework renderer for Preact.
 *
 * This is the server side of the client renderer at the island's
 * renderer-url, so slots render the way @astrojs/preact renders them.
 *
 * The file name is the #framework it serves. The runner waits for the
 * returned promise, so components can suspend while they fetch.
 *
 * @see \Drupal\canvas_ssr\JsModules\Provider\SsrOnlyModuleProvider
 * @see \Drupal\canvas_ssr\ComponentInfo\MainModule
 * @see \Drupal\canvas_ssr\Element\AstroIslandSsr
 */

// preact/compat, which "react" is in Canvas's import map. Every island
// renders with it, whether or not its own modules import it. Most do,
// through drupal-canvas, and loading it only for those would make an
// island's markup depend on what rendered before it. In the browser, it
// changes the markup of a compat-free component in one way: a camelCase
// SVG attribute on an <svg> element, like strokeWidth, gets its hyphenated
// name. ADR 11 says why that's accepted.
import 'react';

import {
  createJsonApiClient,
  drupalSettingsToCanvasContext,
  drupalSettingsToJsonApiRuntimeConfig,
  readCanvasDataV0,
} from 'drupal-canvas';
import { CanvasContextProvider, JsonApiClientProvider } from 'drupal-canvas/react';
import { createElement, Fragment, options } from 'preact';
import { renderToStringAsync } from 'preact-render-to-string';
import { resetIslandFetches } from 'swr';

import { freezeDeferred, watch } from '@/canvas_ssr/realm-setup';

// Starts what a render throws when its markup would parse differently from
// what the component renders in the browser. Renderer.php looks for it.
const MISMATCH = 'SSR mismatch: ';

// Elements whose content the HTML parser reads as text, not markup: script,
// raw text and escapable raw text elements, in the HTML namespace. The list
// is the HTML standard's, and closed. In the browser, Preact sets their
// content as text, so it's inert whatever it holds. In server-rendered HTML
// the parser reads it, and only stops at the element's own end tag.
//
// Raw text elements don't decode character references, so text Preact
// escapes as one shows differently.
const RAW_TEXT = new Set([
  'script', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript',
]);
// These do decode them, so escaped text shows as it would in the browser.
const ESCAPABLE_RAW_TEXT = new Set(['textarea', 'title']);
const AS_GIVEN = new Set(['children', 'dangerouslySetInnerHTML', 'style', 'ref']);
// What no attribute's name can have in it. The browser refuses to set one.
const NOT_A_NAME = /[\s\\/='"\0<>]/;

// What a style property's name is made of. A custom property's can have
// more in it.
const STYLE_NAME = /^(?:--(?:[\w-]|[^\0-\x7F])+|[A-Za-z-]+)$/;
const CLOSES = new Map([['(', ')'], ['[', ']'], ['{', '}']]);

/**
 * Gives a style value as the one declaration the browser would set from it.
 *
 * In the browser, Preact sets each property of a style object on its own,
 * and the browser sets nothing for a value that isn't one declaration's.
 * Server-rendered, the properties are joined into one attribute, where a
 * ";" in a value would start another declaration, "!important" would count,
 * and a string or bracket left open would take in what follows.
 *
 * This reads only as much CSS as it takes to tell where a value ends.
 * Whether the value suits its property is left to the browser, which reads
 * the attribute as it would the property.
 *
 * @param {string} value
 *   The value.
 *
 * @return {string|null}
 *   The value, with anything it left open closed, and a backslash at its
 *   end read, as the browser does both.
 *   NULL if the browser would set nothing.
 */
function asOneDeclaration(value) {
  const length = value.length;
  const open = [];
  let closing = '';
  let i = 0;
  while (i < length) {
    const char = value[i];
    if (char === '\\') {
      // An escape takes the next character, which can't be a newline. At
      // the value's end, the browser reads it as a replacement character.
      if (i + 1 >= length) value = value.slice(0, -1) + '�';
      else if (/[\n\r\f]/.test(value[i + 1])) return null;
      i += 2;
    }
    else if (char === '"' || char === "'") {
      closing = char;
      for (i++; i < length; i++) {
        if (value[i] === '\\') {
          // The browser ignores one at the end of a string it closes.
          if (++i >= length) value = value.slice(0, -1);
        }
        else if (/[\n\r\f]/.test(value[i])) return null;
        else if (value[i] === char) {
          closing = '';
          i++;
          break;
        }
      }
    }
    else if (char === '/' && value[i + 1] === '*') {
      const end = value.indexOf('*/', i + 2);
      if (end === -1) closing = '*/';
      i = end === -1 ? length : end + 2;
    }
    else if (char === '(' && /(?:^|[^\w-])url$/i.test(value.slice(0, i)) && !/^\s*["']/.test(value.slice(i + 1))) {
      // A URL without quotes ends at the next ")", whatever is before it,
      // and only some characters can be: control characters can't.
      // eslint-disable-next-line no-control-regex
      const url = /^\s*(?:[^\s"'()\\\0-\x08\x0B\x0E-\x1F\x7F]|\\[^\n\r\f])*(\\$)?\s*(\)?)/.exec(value.slice(i + 1));
      i += 1 + url[0].length;
      if (url[2] === '') {
        if (i < length) return null;
        if (url[1]) value = value.slice(0, -1) + '�';
        closing = ')';
      }
    }
    else {
      if (CLOSES.has(char)) open.push(CLOSES.get(char));
      else if (char === ')' || char === ']' || char === '}') {
        if (open.pop() !== char) return null;
      }
      else if (open.length === 0 && (char === ';' || char === '!')) return null;
      i++;
    }
  }
  return value + closing + open.reverse().join('');
}

/**
 * Gives a style object as the browser would set it.
 *
 * @param {object} style
 *   The style prop.
 *
 * @return {object}
 *   The same object if it's fine as it is. Otherwise a copy, without the
 *   properties the browser wouldn't set.
 */
function asBrowserSets(style) {
  const kept = {};
  let same = true;
  for (const [name, value] of Object.entries(style)) {
    let set = value;
    if (!STYLE_NAME.test(name)) set = null;
    // The browser sets the string an object makes.
    else if (typeof value === 'string' || typeof value === 'object') {
      set = value === null ? null : asOneDeclaration(String(value));
    }
    same &&= set === value;
    if (set !== null) kept[name] = set;
  }
  return same ? style : kept;
}

/**
 * Throws if an element's markup would parse differently from the browser's.
 *
 * A style object is made to say what the browser would set from it, on any
 * element. Only the elements below are checked for the rest. Elsewhere
 * dangerouslySetInnerHTML is markup in the browser too, and text and
 * attributes are escaped. Within SVG and MathML, script and style aren't raw
 * text, so this refuses some content there that would have been fine.
 *
 * @param {object} vnode
 *   A vnode as it's created.
 */
function checkMarkup(vnode) {
  if (typeof vnode.type !== 'string') return;
  for (const [key, value] of Object.entries(vnode.props ?? {})) {
    if (NOT_A_NAME.test(key)) {
      const rest = { ...vnode.props };
      delete rest[key];
      vnode.props = rest;
    }
    else if (typeof value === 'object' && value !== null && !AS_GIVEN.has(key)) {
      vnode.props = { ...vnode.props, [key]: String(value) };
    }
  }
  const style = vnode.props?.style;
  if (typeof style === 'object' && style !== null) {
    const set = asBrowserSets(style);
    if (set !== style) vnode.props = { ...vnode.props, style: set };
  }
  // The browser sets innerHTML to the string a value makes, so that's what
  // is checked below, and rendered.
  const given = vnode.props?.dangerouslySetInnerHTML?.__html;
  if (given !== undefined && given !== null && typeof given !== 'string') {
    vnode.props = { ...vnode.props, dangerouslySetInnerHTML: { __html: String(given) } };
  }
  // The HTML parser ignores the case of tag names.
  const name = vnode.type.toLowerCase();
  if (name === 'plaintext') {
    throw new Error(`${MISMATCH}<plaintext> never ends in server-rendered HTML, so the rest of the page would be its text.`);
  }
  const raw = RAW_TEXT.has(name);
  if (!raw && !ESCAPABLE_RAW_TEXT.has(name)) return;

  const props = vnode.props ?? {};
  const html = props.dangerouslySetInnerHTML?.__html;
  if (html !== undefined && html !== null) {
    const lower = String(html).toLowerCase();
    if (lower.includes(`</${name}`)) {
      throw new Error(`${MISMATCH}<${name}>'s dangerouslySetInnerHTML contains "</${name}", which would end the element early in server-rendered HTML. Escape the "<", such as with "\\u003c" in JSON.`);
    }
    // After "<!--", a "<script" keeps the element open past its end tag.
    const comment = lower.indexOf('<!--');
    if (name === 'script' && comment !== -1 && lower.includes('<script', comment)) {
      throw new Error(`${MISMATCH}<script>'s dangerouslySetInnerHTML contains "<!--" and then "<script", which can keep the element open past its end tag in server-rendered HTML. Escape the "<", such as with "\\u003c" in JSON.`);
    }
  }

  for (const child of [props.children].flat(Infinity)) {
    if (child === null || child === undefined || typeof child === 'boolean') continue;
    if (typeof child === 'object') {
      throw new Error(`${MISMATCH}<${name}> has an element as a child, which server-rendered HTML would hold as text.`);
    }
    if (raw && /["&<]/.test(String(child))) {
      throw new Error(`${MISMATCH}<${name}>'s text contains a quote, "&" or "<", which server rendering escapes as a character reference that <${name}> doesn't decode. Give it through dangerouslySetInnerHTML instead.`);
    }
  }
}

// compat has added to preact core now, so its exports can be frozen.
freezeDeferred();

// options can't be frozen, since preact-render-to-string sets a flag on it
// for every render. So the realm setup module looks after it as it does
// the globals: each island starts from options as libraries left them
// while they evaluated, with preact/hooks and compat's hooks, and any a
// library adds, as a signals library does, without what an earlier island
// set.
watch(options, "preact's options");

/**
 * Has every vnode checked, for this island.
 *
 * Every vnode, from h(), createElement() and JSX alike, is created through
 * options.vnode, including those components create while rendering. So a
 * mismatch fails the render, and the island falls back to CSR. The hook
 * calls the one libraries left first, which is compat's, so it checks what
 * compat would render.
 *
 * A component that wraps the vnode hook wraps this one, for its own island
 * only.
 */
function checkEveryVnode() {
  const libraryHook = options.vnode;
  options.vnode = (vnode) => {
    libraryHook?.(vnode);
    checkMarkup(vnode);
  };
}

// Renders a slot the way @astrojs/preact does in the browser.
const StaticHtml = ({ value, name }) => {
  if (!value) return null;
  return createElement('astro-slot', {
    name,
    dangerouslySetInnerHTML: { __html: value },
  });
};

/**
 * @param {Function} Component
 *   The component.
 * @param {{props: Object, slots: string[], preview: boolean}} payload
 *   The props, which of them are slots, and whether it's a preview.
 *
 * @return {Promise<string>}
 *   The rendered HTML.
 */
export default async function render(Component, payload) {
  checkEveryVnode();
  // Nothing an earlier island fetched is seen, even if it threw first.
  resetIslandFetches();
  try {
    return await renderIsland(Component, payload);
  }
  finally {
    resetIslandFetches();
  }
}

/**
 * Wraps a component's vnode in what Canvas's hooks read from.
 *
 * As Canvas's island script does in the browser: the page and site context
 * for usePageContext() and useSiteContext(), the interface translations for
 * useTranslation(), on a Canvas that gives them, and a JSON:API client for
 * useJsonApiClient(). All come from the island's drupalSettings, so a
 * component gets only its graph's translations and the data it declared,
 * and a hook gives null for the rest.
 *
 * Each island gets a client of its own, since a client keeps what it learns
 * about the site from the responses it gets.
 *
 * @param {Object} vnode
 *   The component's vnode.
 * @param {boolean} preview
 *   Whether the island is a preview, whose client reads working copies.
 *
 * @return {Object}
 *   The wrapped vnode.
 */
function withCanvasContext(vnode, preview) {
  const data = readCanvasDataV0();
  const config = drupalSettingsToJsonApiRuntimeConfig(data, { preview });
  if (config !== null) {
    const client = createJsonApiClient({ ...config, credentials: 'same-origin' });
    vnode = createElement(JsonApiClientProvider, { client }, vnode);
  }
  return createElement(CanvasContextProvider, { context: drupalSettingsToCanvasContext(data) }, vnode);
}

/**
 * Renders the island.
 *
 * @param {Function} Component
 *   The component.
 * @param {{props: Object, slots: string[], preview: boolean}} payload
 *   The props, which of them are slots, and whether it's a preview.
 *
 * @return {Promise<string>}
 *   The rendered HTML.
 */
function renderIsland(Component, payload) {
  const props = payload.props;
  const slotNames = payload.slots;

  // Slots arrive as HTML strings.
  for (const slotName of slotNames) {
    const value = props[slotName];
    if (typeof value !== 'string' || value.length === 0) continue;
    props[slotName] = createElement(StaticHtml, { value, name: slotName });
  }

  // The Fragment gives the async renderer a place to catch a suspension.
  // Strip Preact's <!--$s--> markers so the markup matches CSR's.
  return renderToStringAsync(
    createElement(Fragment, null, withCanvasContext(createElement(Component, props), payload.preview === true)),
  ).then((html) => html.replace(/<!--\/?\$s-->/g, ''));
}
