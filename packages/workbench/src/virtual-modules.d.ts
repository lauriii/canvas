/**
 * @file
 * Ambient declarations for virtual modules provided by the Drupal Canvas Vite
 * plugin, which Workbench composes into its dev server and preview builds.
 */

declare module 'virtual:drupal-canvas/site-data' {
  import type { CanvasSiteData } from '@drupal-canvas/vite-plugin';

  const siteData: CanvasSiteData;
  export default siteData;
}
