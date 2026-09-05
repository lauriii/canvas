import { revalidateTag } from 'next/cache';
import { readPublishWebhook } from '@drupal-canvas/headless/server';

export interface RevalidateRouteHandlerOptions {
  /**
   * The shared secret matching the site's
   * `$settings['canvas_headless_publish_webhook_secret']`. Defaults to
   * `process.env.CANVAS_PUBLISH_WEBHOOK_SECRET`.
   */
  secret?: string;
}

export interface RevalidateRouteHandler {
  /** Mount at, for example, app/api/canvas/revalidate/route.ts. */
  POST: (request: Request) => Promise<Response>;
}

/**
 * A route handler that revalidates on the Canvas publish webhook.
 *
 * It verifies the `X-Canvas-Signature` HMAC, parses the payload, and calls
 * `revalidateTag()` for each invalidated cache tag. Tag each cached page fetch
 * with its `page.cacheability.tags` (for example inside a `use cache` function
 * via `cacheTag(...page.cacheability.tags)`), and this handler revalidates
 * exactly the pages a publish touched, indirect dependencies included, because
 * the webhook carries the same Drupal cache tags the pages depend on.
 *
 * ```ts
 * // app/api/canvas/revalidate/route.ts
 * import { createRevalidateRouteHandler } from '@drupal-canvas/headless-next';
 * export const { POST } = createRevalidateRouteHandler();
 * ```
 */
export function createRevalidateRouteHandler(
  options: RevalidateRouteHandlerOptions = {},
): RevalidateRouteHandler {
  const secret = options.secret ?? process.env.CANVAS_PUBLISH_WEBHOOK_SECRET;
  return {
    POST: async (request: Request): Promise<Response> => {
      const result = await readPublishWebhook({
        rawBody: await request.text(),
        signature: request.headers.get('x-canvas-signature'),
        secret,
      });
      if (!result.ok) {
        return new Response(result.message, { status: result.status });
      }
      for (const tag of result.payload.tags) {
        // The 'max' profile marks matching cache entries stale and serves
        // stale-while-revalidate, so a publish never blocks on regeneration.
        revalidateTag(tag, 'max');
      }
      return Response.json({ revalidated: result.payload.tags.length });
    },
  };
}
