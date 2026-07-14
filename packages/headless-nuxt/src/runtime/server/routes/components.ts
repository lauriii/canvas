// The virtual manifest module exists only at build time; its ambient
// declaration must travel into a consumer's program by reference — a
// runtime import of a .d.ts file is not bundleable.
// eslint-disable-next-line @typescript-eslint/triple-slash-reference
/// <reference path="../virtual.d.ts" />
import manifest from '#canvas-components-manifest';
import { defineEventHandler, toWebRequest } from 'h3';
import {
  buildComponentMetadataPayload,
  createComponentMetadataHandler,
} from '@drupal-canvas/headless/components-endpoint';

/**
 * The component metadata endpoint (see the framework-free handler in
 * @drupal-canvas/headless/components-endpoint for the payload and its
 * proof-by-redemption protection). Mounted at /api/canvas/components by
 * the module, methods unrestricted so the handler can answer the CORS
 * preflight itself.
 *
 * `import.meta.dev` is Nitro's build-time mode flag: `nuxt dev` scans the
 * codebase live on every request, while a production build serves the
 * manifest the module inlined into the server bundle at build time — the
 * deployed `.output/` is self-contained.
 */
const handler = createComponentMetadataHandler({
  isProduction: !import.meta.dev,
  loadManifest: async () => manifest,
  scanComponents: () => buildComponentMetadataPayload(),
});

export default defineEventHandler((event) => {
  const request = toWebRequest(event);
  if (event.method === 'OPTIONS') {
    return handler.OPTIONS(request);
  }
  if (event.method === 'GET') {
    return handler.GET(request);
  }
  return new Response(null, {
    status: 405,
    headers: { Allow: 'GET, OPTIONS' },
  });
});
