/**
 * The production component manifest, inlined into the server bundle by the
 * canvas() integration's Vite plugin (see ./integration.ts). Null under
 * `astro dev`, where the endpoint scans the codebase live.
 */
declare module 'virtual:@drupal-canvas/headless-astro/manifest' {
  import type { ComponentMetadataPayload } from '@drupal-canvas/headless/components-endpoint';

  const manifest: ComponentMetadataPayload | null;
  export default manifest;
}

declare module 'virtual:@drupal-canvas/headless/components' {
  const components: Record<string, unknown>;
  export default components;
}

/**
 * Whether this build's pages are prerendered, injected as a Vite define by
 * the canvas() integration (see ./integration.ts). True when the build's
 * Astro `output` option was 'static'; the draft activation route reads it
 * to refuse a session that prerendered pages could never show. Undefined
 * where no define ran (unit tests), so reads go through a typeof guard.
 */
declare const __CANVAS_STATIC_OUTPUT__: boolean;

declare module 'virtual:@drupal-canvas/headless/global.css' {}
