// The virtual manifest module exists only at build time; its ambient
// declaration must travel into a consumer's program by reference — a
// runtime import of a .d.ts file is not bundleable.
// eslint-disable-next-line @typescript-eslint/triple-slash-reference
/// <reference path="../virtual.d.ts" />
import manifest from 'virtual:@drupal-canvas/headless-astro/manifest';
import {
  buildComponentMetadataPayload,
  createComponentMetadataHandler,
} from '@drupal-canvas/headless/components-endpoint';

import type { APIRoute } from 'astro';

export const prerender = false;

/**
 * The component metadata endpoint (see the framework-free handler in
 * @drupal-canvas/headless/components-endpoint for the payload and its
 * proof-by-redemption protection). Injected at /api/canvas/components by
 * the canvas() integration, whose Vite plugin also provides the virtual
 * manifest module — mounting this route without the integration does not
 * build.
 *
 * `import.meta.env.PROD` is the production signal: in an `astro build`
 * output the endpoint serves the manifest inlined into the server bundle
 * at build time, so the deployed `dist/` is self-contained; `astro dev`
 * scans the codebase live on every request instead.
 */
const handler = createComponentMetadataHandler({
  isProduction: import.meta.env.PROD,
  loadManifest: async () => manifest,
  scanComponents: () => buildComponentMetadataPayload(),
});

export const GET: APIRoute = ({ request }) => handler.GET(request);
export const OPTIONS: APIRoute = ({ request }) => handler.OPTIONS(request);
