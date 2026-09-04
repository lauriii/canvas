import { readPublishWebhook } from '@drupal-canvas/headless/server';

import type { PublishPayload } from '@drupal-canvas/headless/server';

/**
 * The shape a TanStack Start server route handler takes: the framework hands
 * the handler a context with the web Request and expects a web Response back.
 */
type ServerRouteHandler = (context: { request: Request }) => Promise<Response>;

export interface RevalidateRouteHandlerOptions {
  /**
   * The shared secret matching the site's
   * `$settings['canvas_headless_publish_webhook_secret']` (or its State value).
   * Defaults to `process.env.CANVAS_PUBLISH_WEBHOOK_SECRET`.
   */
  secret?: string;
  /**
   * Invalidates the cache for the published content. TanStack Start has no
   * framework-native tag revalidation, so the app supplies this: clear the
   * Nitro cache entries for the affected tags, purge a CDN by surrogate key,
   * or trigger a rebuild. Receives the parsed publish payload.
   */
  revalidate: (payload: PublishPayload) => void | Promise<void>;
}

export interface RevalidateRouteHandler {
  /** Mount at src/routes/api/canvas/revalidate.ts as the POST handler. */
  POST: ServerRouteHandler;
}

/**
 * A revalidation route handler for the Canvas publish webhook.
 *
 * It verifies the `X-Canvas-Signature` HMAC and parses the payload with the
 * shared core spine, then hands the payload to the app's `revalidate`
 * callback. TanStack Start exposes no `revalidateTag` equivalent, so the
 * invalidation itself is app or host specific; the callback is where the
 * Nitro cache invalidation, CDN surrogate-key purge, or rebuild trigger
 * lives. The payload carries the invalidated cache tags to act on.
 *
 * ```ts
 * // src/routes/api/canvas/revalidate.ts
 * import { createFileRoute } from '@tanstack/react-router';
 * import { createRevalidateRouteHandler } from '@drupal-canvas/headless-tanstack-start';
 *
 * const { POST } = createRevalidateRouteHandler({
 *   revalidate: async ({ tags }) => {
 *     // Invalidate the Nitro cache entries or purge the CDN for these tags.
 *   },
 * });
 * export const Route = createFileRoute('/api/canvas/revalidate')({
 *   server: { handlers: { POST } },
 * });
 * ```
 */
export function createRevalidateRouteHandler(
  options: RevalidateRouteHandlerOptions,
): RevalidateRouteHandler {
  const secret = options.secret ?? process.env.CANVAS_PUBLISH_WEBHOOK_SECRET;
  return {
    POST: async ({ request }: { request: Request }): Promise<Response> => {
      const result = await readPublishWebhook({
        rawBody: await request.text(),
        signature: request.headers.get('x-canvas-signature'),
        secret,
      });
      if (!result.ok) {
        return new Response(result.message, { status: result.status });
      }
      await options.revalidate(result.payload);
      return Response.json({ revalidated: result.payload.tags.length });
    },
  };
}
