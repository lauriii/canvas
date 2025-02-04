/* eslint-disable no-unused-vars, import/no-anonymous-default-export */
/* Add any additional functions/hooks to expose to in-browser JS components here.

  In order to have Astro bundle code with un-minified names, we use dynamic imports in this Stub component.
  Using dynamic imports results in Rollup (Astro uses Vite which uses Rollup) exporting the all hooks,
  jsx, jsxs, and Fragment functions, with names, from the corresponding module bundles, which
  can then be imported by the in-browser JS components. */

const { ...preactHooks } = await import('preact/hooks');
const { jsx, jsxs, Fragment } = await import('preact/jsx-runtime');

export default function () {}
