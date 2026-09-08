import {
  defineEventHandler,
  getHeader,
  readRawBody,
  setResponseStatus,
} from 'h3';
import { useStorage } from 'nitropack/runtime';
import { readPublishWebhook } from '@drupal-canvas/headless/server';

import type { PublishPayload } from '@drupal-canvas/headless/server';
import type { EventHandler } from 'h3';

/**
 * The Nitro cache storage group Canvas page caches must use so this handler
 * can clear them. Pass it as the `group` option of a `defineCachedEventHandler`
 * or `defineCachedFunction` that renders Canvas pages, for example
 * `defineCachedEventHandler(handler, { group: 'canvas', swr: true })`. Nitro
 * stores such entries under the `cache:canvas:` key prefix, which the default
 * revalidation clears without touching the rest of the Nitro cache.
 */
export const CANVAS_CACHE_GROUP = 'canvas';

export interface RevalidateEventHandlerOptions {
  /**
   * The shared secret matching the site's
   * `$settings['canvas_headless_publish_webhook_secret']`. Defaults to
   * `process.env.CANVAS_PUBLISH_WEBHOOK_SECRET`.
   */
  secret?: string;
  /**
   * Precise, app-controlled invalidation. When provided it is called with the
   * publish payload instead of the default group clear, so the app can map the
   * invalidated `payload.tags` back to exactly the cache keys they touched.
   * This is the tag-accurate path, the closest Nitro gets to Next's
   * `revalidateTag`.
   */
  revalidate?: (payload: PublishPayload) => void | Promise<void>;
}

/**
 * A Nitro/h3 event handler that revalidates on the Canvas publish webhook.
 *
 * It verifies the `X-Canvas-Signature` HMAC, parses the payload, and then
 * invalidates cached pages so the next request regenerates them.
 *
 * Nitro's cache is key-based, not tag-based, so there is no direct equivalent
 * of Next's `revalidateTag`: the webhook's Drupal cache tags cannot be matched
 * against cache entries without an app-maintained tag-to-key map. This handler
 * is therefore honest about being coarser than the Next.js adapter, and offers
 * two paths:
 *
 * - Default: clear the documented `group: 'canvas'` cache group (see
 *   `CANVAS_CACHE_GROUP`). Any publish clears all Canvas page caches at once,
 *   and only those; unrelated Nitro caches are left untouched. Combined with
 *   SWR route rules (for example `routeRules: { '/**': { swr: true } }`) or a
 *   `swr: true` cached handler, pages keep serving the last render while the
 *   next request regenerates them, so a publish never blocks a visitor.
 * - Precise: pass an `options.revalidate` callback to invalidate exactly the
 *   keys a publish touched, using `payload.tags` and your own tag-to-key map.
 *
 * Either way the response is `{ revalidated: <number of tags> }`.
 *
 * ```ts
 * // server/routes/api/canvas/revalidate.post.ts
 * import { createRevalidateEventHandler } from '@drupal-canvas/headless-nuxt/server';
 * export default createRevalidateEventHandler();
 * ```
 */
export function createRevalidateEventHandler(
  options: RevalidateEventHandlerOptions = {},
): EventHandler {
  const secret = options.secret ?? process.env.CANVAS_PUBLISH_WEBHOOK_SECRET;
  return defineEventHandler(async (event) => {
    const result = await readPublishWebhook({
      rawBody: (await readRawBody(event)) ?? '',
      signature: getHeader(event, 'x-canvas-signature'),
      secret,
    });
    if (!result.ok) {
      setResponseStatus(event, result.status);
      return result.message;
    }
    if (options.revalidate) {
      // The app owns precise invalidation from the payload's cache tags.
      await options.revalidate(result.payload);
    } else {
      // Coarse but safe: clear only the documented Canvas cache group.
      await useStorage('cache').clear(CANVAS_CACHE_GROUP);
    }
    return { revalidated: result.payload.tags.length };
  });
}
