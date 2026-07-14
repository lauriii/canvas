import { getDraftServer } from '../server';

import type { APIRoute } from 'astro';

export const prerender = false;

/**
 * Draft-mode exit. POST, not GET: exiting changes state (it clears the
 * session cookies), and a GET endpoint reached by links would be eligible
 * for prefetching — a framework or browser prefetch could silently end the
 * session. Injected at /api/disable-draft by the canvas() integration.
 */
export const POST: APIRoute = (context) =>
  getDraftServer(context).disableDraftMode();
