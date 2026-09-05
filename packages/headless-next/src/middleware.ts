/**
 * @file
 * The Next.js middleware that sends the `frame-ancestors` policy. Loaded by
 * the app's own `middleware.ts`, which Next.js compiles for the Edge
 * runtime, so nothing here may reach `next/headers` or Node built-ins.
 *
 * Request time, not build time. `next.config`'s `headers()` can only carry
 * a static rule plus a cookie-matching condition, and that condition is
 * evaluated by whatever routing layer serves the app: Next.js's own server
 * matches it against the percent-DECODED cookie value, while Vercel's
 * routing layer matches the raw `Cookie` header. Cookie values are
 * percent-encoded on the wire, so one build produced two behaviors — on
 * Vercel the editor origin was silently never added and the editor's
 * iframe was refused. Reading the cookie here removes the routing layer
 * from the question entirely, and puts this adapter on the same
 * request-time footing as the Astro, Nuxt, and TanStack Start ones.
 */

import { NextResponse } from 'next/server';
import {
  DRAFT_DATA_COOKIE_NAME,
  parseDraftData,
} from '@drupal-canvas/headless';
import {
  mergeFrameAncestors,
  resolveFrameAncestors,
} from '@drupal-canvas/headless/server';

import type { NextRequest } from 'next/server';

/**
 * Merges the `frame-ancestors` directive into a middleware response's
 * Content-Security-Policy, restricting who may embed the app.
 *
 * Merged, not set: a policy the app already sends (default-src,
 * script-src, ...) is preserved, and an application-owned frame-ancestors
 * directive remains authoritative. Otherwise responses are 'self'-only,
 * and a request carrying a draft session also admits the exact editor
 * origin from that session's signed renewal URL.
 *
 * Call it from an app that already has middleware of its own:
 *
 * ```ts
 * // proxy.ts (middleware.ts before Next.js 16, exporting `middleware`)
 * import { applyCanvasHeaders } from '@drupal-canvas/headless-next/middleware';
 * import { NextResponse } from 'next/server';
 *
 * import type { NextRequest } from 'next/server';
 *
 * export function proxy(request: NextRequest) {
 *   return applyCanvasHeaders(request, NextResponse.next());
 * }
 * ```
 */
export function applyCanvasHeaders<T extends Response>(
  request: NextRequest,
  response: T,
): T {
  // NextRequest.cookies returns the percent-decoded value, which is what
  // the session was serialized from.
  const draftData = parseDraftData(
    request.cookies.get(DRAFT_DATA_COOKIE_NAME)?.value ?? null,
  );
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
}

/**
 * The drop-in middleware for an app with no middleware of its own:
 *
 * ```ts
 * // proxy.ts (middleware.ts before Next.js 16, exporting `middleware`)
 * export { canvasMiddleware as proxy } from '@drupal-canvas/headless-next/middleware';
 *
 * export const config = {
 *   // Every document response, skipping the static asset paths that are
 *   // never framed.
 *   matcher: ['/((?!_next/static|_next/image|favicon.ico).*)'],
 * };
 * ```
 *
 * The file belongs at the project root, or in `src/` when the app keeps its
 * code there — Next.js looks in exactly those two places, and resolves each
 * convention by its own export name.
 */
export function canvasMiddleware(request: NextRequest): NextResponse {
  return applyCanvasHeaders(request, NextResponse.next());
}
