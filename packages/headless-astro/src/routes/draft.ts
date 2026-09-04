import { getDraftServer } from '../server';

import type { APIRoute } from 'astro';

// Draft activation must run per request even in a mostly prerendered app.
export const prerender = false;

/**
 * Draft-mode activation: redeems the `?assertion=` preview URL Drupal
 * minted and redirects to the signed entry path. Injected at /api/draft by
 * the canvas() integration.
 *
 * In a hybrid build (`output: 'static'` with an adapter) this route runs,
 * but the pages were prerendered at build time with published content — a
 * draft session would activate and then silently show stale public pages.
 * Activation refuses with 501 instead, so the failure is visible where it
 * happens.
 */
export const GET: APIRoute = (context) => {
  // Injected as a Vite define by the canvas() integration; the typeof
  // guard keeps the module loadable where no define ran (unit tests).
  if (
    typeof __CANVAS_STATIC_OUTPUT__ !== 'undefined' &&
    __CANVAS_STATIC_OUTPUT__
  ) {
    return new Response(
      "This build's pages are prerendered (output: 'static'): they were rendered at build time with published content and cannot show a draft session. Deploy the server-rendered app (output: 'server') for draft preview. See the @drupal-canvas/headless-astro README, Static and hybrid builds.",
      { status: 501, headers: { 'Content-Type': 'text/plain;charset=UTF-8' } },
    );
  }
  return getDraftServer(context).enableDraftMode(context.request);
};
