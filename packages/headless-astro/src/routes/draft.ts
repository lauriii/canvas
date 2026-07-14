import { getDraftServer } from '../server';

import type { APIRoute } from 'astro';

// Draft activation must run per request even in a mostly prerendered app.
export const prerender = false;

/**
 * Draft-mode activation: redeems the `?assertion=` preview URL Drupal
 * minted and redirects to the signed entry path. Injected at /api/draft by
 * the canvas() integration.
 */
export const GET: APIRoute = (context) =>
  getDraftServer(context).enableDraftMode(context.request);
