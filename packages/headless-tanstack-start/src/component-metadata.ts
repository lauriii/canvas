// The virtual manifest module exists only at build time; its ambient
// declaration must travel into a consumer's program by reference — a
// runtime import of a .d.ts file is not bundleable.
// eslint-disable-next-line @typescript-eslint/triple-slash-reference
/// <reference path="./virtual.d.ts" />
import manifest from 'virtual:@drupal-canvas/headless-tanstack-start/manifest';
import {
  buildComponentMetadataPayload,
  createComponentMetadataHandler,
} from '@drupal-canvas/headless/components-endpoint';

type ServerRouteHandler = (context: { request: Request }) => Promise<Response>;

/**
 * Creates the component metadata endpoint handlers (see the framework-free
 * handler in @drupal-canvas/headless/components-endpoint for the payload
 * and its proof-by-redemption protection). Requires the canvas() Vite
 * plugin (the `./vite` export), which provides the virtual manifest module
 * this factory imports — mounting the route without the plugin does not
 * build.
 *
 * `import.meta.env.PROD` is the production signal: a `vite build` output
 * serves the manifest inlined into the server bundle at build time, so the
 * deployed output is self-contained; `vite dev` scans the codebase live on
 * every request instead.
 *
 * ```ts
 * // src/routes/api/canvas.components.ts
 * import { createFileRoute } from '@tanstack/react-router';
 * import { createComponentMetadataHandlers } from '@drupal-canvas/headless-tanstack-start';
 *
 * const { GET } = createComponentMetadataHandlers();
 * export const Route = createFileRoute('/api/canvas/components')({
 *   server: { handlers: { GET } },
 * });
 * ```
 */
export function createComponentMetadataHandlers(): {
  GET: ServerRouteHandler;
} {
  const handler = createComponentMetadataHandler({
    isProduction: import.meta.env.PROD,
    loadManifest: async () => manifest,
    scanComponents: () => buildComponentMetadataPayload(),
  });
  return {
    GET: ({ request }) => handler.GET(request),
  };
}
