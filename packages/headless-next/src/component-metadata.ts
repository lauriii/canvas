/**
 * @file
 * The component metadata endpoint for Next.js: the framework-free handler
 * from the SDK core, wired to the manifest withCanvas() inlined into the
 * server bundle through env injection at build time. No filesystem is
 * touched from the route's module graph — a dynamic file read there makes
 * Next.js's file tracer sweep the whole project into the route's output.
 *
 * Next.js sets NODE_ENV, the handler's default production signal, so no
 * mode wiring is needed here. In development the inlined variable is
 * absent and the endpoint scans the codebase live.
 *
 * Mount in a route file with literal segment config — Next.js reads these
 * statically, so the factory cannot supply them:
 *
 * ```ts
 * // app/api/canvas/components/route.ts
 * import { createComponentMetadataHandler } from '@drupal-canvas/headless-next';
 * export const runtime = 'nodejs';
 * export const dynamic = 'force-dynamic';
 * export const { GET, OPTIONS } = createComponentMetadataHandler();
 * ```
 */

import { createComponentMetadataHandler as createCoreHandler } from '@drupal-canvas/headless/components-endpoint/handler';

import type {
  ComponentMetadataHandlerOptions,
  ComponentMetadataPayload,
} from '@drupal-canvas/headless/components-endpoint/handler';

export type { ComponentMetadataHandlerOptions };

export function createComponentMetadataHandler(
  options: ComponentMetadataHandlerOptions = {},
): ReturnType<typeof createCoreHandler> {
  return createCoreHandler({
    loadManifest: async () => {
      const inlined = process.env.CANVAS_COMPONENT_MANIFEST_JSON;
      return inlined ? (JSON.parse(inlined) as ComponentMetadataPayload) : null;
    },
    // Next.js defines NODE_ENV at build time, so in production builds
    // this whole branch — the dynamic import included — is eliminated as
    // dead code, keeping discovery's dynamic filesystem work out of the
    // route's module graph (a file tracer would otherwise sweep the whole
    // project into the route's output).
    scanComponents:
      process.env.NODE_ENV === 'development'
        ? async () => {
            const { buildComponentMetadataPayload } =
              await import('@drupal-canvas/headless/components-endpoint');
            return buildComponentMetadataPayload();
          }
        : undefined,
    ...options,
  });
}
