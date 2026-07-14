import { getDraftServer } from '../server';

import type { APIRoute } from 'astro';

export const prerender = false;

/**
 * In-place session renewal: POST `{assertion}`, answered with
 * `{tokenExpiresAt}`. Injected at /api/draft/renew by the canvas()
 * integration.
 */
export const POST: APIRoute = (context) =>
  getDraftServer(context).renewDraftSession(context.request);
