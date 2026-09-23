import {
  mergeFrameAncestors,
  resolveFrameAncestors,
} from '@drupal-canvas/headless/server';

import { getDraftData } from './server';

import type { MiddlewareHandler } from 'astro';

/**
 * Merges the `frame-ancestors` directive into every response's
 * Content-Security-Policy, restricting who may embed the app.
 * Registered by the canvas() integration. Merged, not set: a policy the
 * app already sends (default-src, script-src, ...) is preserved. An
 * application-owned frame-ancestors directive remains authoritative.
 * Otherwise, the shared resolver uses CANVAS_EDITOR_ORIGINS when set, or
 * the site and draft editor origins by default, always including 'self'.
 */
export const onRequest: MiddlewareHandler = async (context, next) => {
  const draftData = await getDraftData(context);
  const response = await next();
  response.headers.set(
    'Content-Security-Policy',
    // Joined with ', ': the standard serialization of a policy list in
    // one header field.
    mergeFrameAncestors(
      response.headers.get('Content-Security-Policy'),
      resolveFrameAncestors(draftData),
    ).join(', '),
  );
  return response;
};
