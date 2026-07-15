import { JS_EXTENSIONS } from '@drupal-canvas/discovery';

/**
 * Component entry extensions accepted by headless discovery.
 *
 * A headless application renders its own components, so framework single-file
 * components are valid implementations even though Drupal never compiles them.
 */
export const COMPONENT_ENTRY_EXTENSIONS = [
  ...JS_EXTENSIONS,
  '.astro',
  '.vue',
  '.svelte',
] as const;
