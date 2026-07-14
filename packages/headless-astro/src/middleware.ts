import {
  mergeFrameAncestors,
  resolveFrameAncestors,
} from '@drupal-canvas/headless/server';

import type { MiddlewareHandler } from 'astro';

/**
 * Merges the `frame-ancestors` directive into every response's
 * Content-Security-Policy, restricting who may embed the app — the Astro
 * counterpart of the header withCanvas() configures for Next.js.
 * Registered by the canvas() integration. Merged, not set: a policy the
 * app already sends (default-src, script-src, ...) is preserved; only the
 * frame-ancestors directive is this SDK's to own.
 *
 * The environment is read per request, not at module load, so the dev
 * server picks up .env changes the same way the rest of the SDK does; see
 * resolveFrameAncestors() for the source list rules.
 */
export const onRequest: MiddlewareHandler = async (_context, next) => {
  const response = await next();
  response.headers.set(
    'Content-Security-Policy',
    // Joined with ', ': the standard serialization of a policy list in
    // one header field.
    mergeFrameAncestors(
      response.headers.get('Content-Security-Policy'),
      resolveFrameAncestors(),
    ).join(', '),
  );
  return response;
};
