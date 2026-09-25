/**
 * @file
 * Ambient declaration for the plugin's virtual site-data module, for
 * consumers that import it with TypeScript.
 */

declare module 'virtual:drupal-canvas/site-data' {
  import type { CanvasSiteData } from '@drupal-canvas/vite-plugin';

  const siteData: CanvasSiteData;
  export default siteData;
}
