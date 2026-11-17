/**
 * Hydration data: what libraries give a hydrated island's first render in
 * the browser.
 *
 * A library that rendered on the server from state the browser doesn't have
 * yet, such as fetched data, provides that state under its name while the
 * island renders, again each time it changes. The main module collects what
 * each library last provided for a hydrated island, once the framework
 * renderer is done, and Canvas's island client renderer gives each to the
 * library of that name in the browser, so its first render there matches
 * the server's.
 *
 * It's encoded by devalue, which carries what JSON can't, such as a Date or
 * a structure that refers to itself. The browser decodes it with the same
 * copy: both import it through Canvas's import map, under a specifier of
 * Canvas's own, so a site's own devalue doesn't replace it.
 *
 * What's provided is the island's own: the main module forgets it before
 * and after each island, so no island carries what another provided.
 *
 * @see ./swr-shim.js
 * @see \Drupal\canvas_ssr\ComponentInfo\MainModule
 * @see \Drupal\canvas_ssr\Element\AstroIslandSsr
 */

import { DevalueError, stringify } from '@drupal-canvas/astro-hydration/hydration-data';

// Starts what collectHydrationData() throws. Renderer.php looks for it.
const ERROR_PREFIX = 'SSR hydration data: ';

// What each library provided for the island, by library name.
const provided = new Map();

/**
 * Provides a library's hydration data for the island being rendered.
 *
 * Providing again under the same name replaces what was provided. The data
 * is collected after the framework renderer is done, and a library may
 * forget its own state by then, so it provides the data itself.
 *
 * @param {string} name
 *   The library's name, which the browser's consumer of it is keyed by.
 * @param {*} data
 *   The data, which devalue must be able to encode, or undefined for none.
 */
export function provideHydrationData(name, data) {
  provided.set(name, data);
}

/**
 * Forgets what every library provided, for the next island.
 */
export function resetHydrationData() {
  provided.clear();
}

/**
 * Collects the island's hydration data.
 *
 * @return {string|null}
 *   Each library's data by its name, encoded by devalue, or NULL if no
 *   library provided any.
 *
 * @throws {Error}
 *   If devalue can't encode a library's data, such as a function or an
 *   instance of a class.
 */
export function collectHydrationData() {
  const data = {};
  for (const [name, value] of provided) {
    if (value !== undefined) {
      data[name] = value;
    }
  }
  if (Object.keys(data).length === 0) {
    return null;
  }
  try {
    return stringify(data);
  } catch (e) {
    if (e instanceof DevalueError) {
      throw new Error(`${ERROR_PREFIX}${e.message} at ${e.path === '' ? 'the top' : e.path}.`);
    }
    throw e;
  }
}
