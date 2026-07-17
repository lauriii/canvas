/**
 * The production component manifest, inlined into the server bundle by the
 * canvas() Vite plugin (see ./vite.ts). Null under `vite dev`, where the
 * endpoint scans the codebase live.
 */
declare module 'virtual:@drupal-canvas/headless-tanstack-start/manifest' {
  import type { ComponentMetadataPayload } from '@drupal-canvas/headless/components-endpoint';

  const manifest: ComponentMetadataPayload | null;
  export default manifest;
}

declare module 'virtual:@drupal-canvas/headless/components' {
  import type { CanvasComponentRegistry } from '@drupal-canvas/headless-react';

  const components: CanvasComponentRegistry;
  export default components;
}
