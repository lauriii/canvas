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
