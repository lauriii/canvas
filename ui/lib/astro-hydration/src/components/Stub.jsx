/* eslint-disable @typescript-eslint/no-unused-vars */
/* Add any additional functions/hooks to expose to in-browser JS components here.

  In order to have Astro bundle code with un-minified names, we use dynamic imports in this Stub component.
  Using dynamic imports results in Rollup (Astro uses Vite which uses Rollup) exporting the all hooks,
  jsx, jsxs, and Fragment functions, with names, from the corresponding module bundles, which
  can then be imported by the in-browser JS components. */

const { ...preact } = await import('preact');
const { ...preactCompat } = await import('preact/compat');
const { ...preactHooks } = await import('preact/hooks');
const { ...jsxRuntime } = await import('@/lib/jsx-runtime-default');
const { default: clsx } = await import('clsx');
const { ...tailwindMerge } = await import('tailwind-merge');
const { cva } = await import('class-variance-authority');
const { DrupalJsonApiParams } = await import('@/lib/jsonapi-params');
const useSwr = await import('@/lib/swr');

const { ...drupalCanvas } = await import('@/lib/drupal-canvas');
// For backward compatibility import separately elements that were moved to the drupal-canvas package
// so they have separate files in dist that can be used in backward compatible import map entries.
const FormattedText = await import('drupal-canvas/FormattedText');
const {
  sortMenu: sortLinksetMenu,
  getPageData,
  getSiteData,
} = await import('drupal-canvas/drupal-utils');
const Image = await import('drupal-canvas/next-image-standalone');
const { cn } = await import('drupal-canvas/utils');
const { JsonApiClient } = await import('drupal-canvas/jsonapi-client');
const { getNodePath, sortMenu } = await import('drupal-canvas/jsonapi-utils');

await import('@/lib/canvas-island.js');

export default function () {}
