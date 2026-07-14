/**
 * The production component manifest, inlined into the Nitro server bundle
 * by the module's `nitro:config` virtual entry (see ../../module.ts). Null
 * under `nuxt dev`, where the endpoint scans the codebase live.
 */
declare module '#canvas-components-manifest' {
  import type { ComponentMetadataPayload } from '@drupal-canvas/headless/components-endpoint';

  const manifest: ComponentMetadataPayload | null;
  export default manifest;
}
