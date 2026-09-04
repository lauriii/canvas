import { readPublishWebhook } from '@drupal-canvas/headless/server';

import type { PublishPayload } from '@drupal-canvas/headless/server';
import type { APIRoute } from 'astro';

// Revalidation runs per request in response to a publish webhook, so the
// route can never be prerendered. The page that mounts the handler must
// also declare `export const prerender = false;` (see the JSDoc below).
export const prerender = false;

export interface RevalidateApiRouteOptions {
  /**
   * The shared secret matching the site's
   * `$settings['canvas_headless_publish_webhook_secret']`. Defaults to
   * `import.meta.env.CANVAS_PUBLISH_WEBHOOK_SECRET`, falling back to
   * `process.env.CANVAS_PUBLISH_WEBHOOK_SECRET`.
   */
  secret?: string;
  /**
   * The host-specific invalidation callback, invoked with the verified
   * publish payload after the signature checks out. This is where the app
   * wires whatever its host exposes: a Vercel bypass-token purge, a Netlify
   * purge, the Bun adapter's `unstable_expirePath`, or a CI rebuild trigger
   * for pure-static output. Without it the handler answers 501, because
   * Astro has no portable on-demand revalidation to fall back on.
   */
  revalidate?: (payload: PublishPayload) => void | Promise<void>;
}

export interface RevalidateApiRoute {
  /** Mount at, for example, src/pages/api/canvas/revalidate.ts. */
  POST: APIRoute;
}

/**
 * An Astro endpoint that reacts to the Canvas publish webhook.
 *
 * It reads the raw request body and the `X-Canvas-Signature` header, verifies
 * the HMAC and parses the payload through the shared `readPublishWebhook`
 * spine, then hands the verified payload to the app-supplied `revalidate`
 * callback.
 *
 * Unlike Next.js, Astro has no framework-native cache-tag or revalidation API.
 * On-demand revalidation is entirely host-specific: the Vercel adapter purges
 * with a bypass token, Netlify has its own purge, the Bun adapter exposes
 * `unstable_expirePath`, and pure-static output has no runtime cache at all, so
 * invalidation there means triggering a rebuild. This handler therefore ships
 * only the verified webhook plumbing and leaves the actual invalidation to the
 * `revalidate` callback, which is where the host wiring goes. For static output
 * that callback typically triggers a rebuild (for example by calling a CI
 * deploy hook). See the "Content invalidation" section of the
 * `@drupal-canvas/headless-astro` README for host-by-host guidance.
 *
 * The webhook's `payload.tags` are the Drupal cache tags a publish
 * invalidated, indirect dependencies included. A CDN-fronted deploy that
 * emitted `Surrogate-Key` from `surrogateKeyHeader(page)` on each page response
 * can purge exactly the affected pages by those keys.
 *
 * The mounting page must opt out of prerendering:
 *
 * ```ts
 * // src/pages/api/canvas/revalidate.ts
 * import { createRevalidateApiRoute } from '@drupal-canvas/headless-astro';
 * export const prerender = false;
 * export const { POST } = createRevalidateApiRoute({
 *   revalidate: async (payload) => {
 *     // Host-specific invalidation, keyed off payload.tags.
 *   },
 * });
 * ```
 */
export function createRevalidateApiRoute(
  options: RevalidateApiRouteOptions = {},
): RevalidateApiRoute {
  const secret =
    options.secret ??
    import.meta.env.CANVAS_PUBLISH_WEBHOOK_SECRET ??
    process.env.CANVAS_PUBLISH_WEBHOOK_SECRET;
  const { revalidate } = options;
  return {
    POST: async (context): Promise<Response> => {
      const result = await readPublishWebhook({
        rawBody: await context.request.text(),
        signature: context.request.headers.get('x-canvas-signature'),
        secret,
      });
      if (!result.ok) {
        return new Response(result.message, { status: result.status });
      }
      if (!revalidate) {
        // The signature is valid, so this is a genuine publish the app is
        // not yet wired to act on. 501 says the endpoint exists but the
        // host-specific step is not implemented, rather than pretending the
        // publish was handled.
        return new Response(
          'On-demand revalidation on Astro requires a host-specific ' +
            'revalidate callback. See the Content invalidation section of the ' +
            '@drupal-canvas/headless-astro README.',
          { status: 501 },
        );
      }
      await revalidate(result.payload);
      return Response.json({ revalidated: result.payload.tags.length });
    },
  };
}
