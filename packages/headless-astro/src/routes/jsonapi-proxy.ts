import { getDraftServer } from '../server';

import type { APIRoute } from 'astro';

export const prerender = false;

/**
 * The same-origin JSON:API proxy portable Code Components reach Drupal
 * through, authenticated from the draft session. Injected at
 * /api/canvas/jsonapi/[...path] (the default CANVAS_JSONAPI_PROXY_PATH) by
 * the canvas() integration, for every method; see
 * createJsonApiProxyHandler() in @drupal-canvas/headless/server.
 */
export const ALL: APIRoute = (context) =>
  getDraftServer(context).handleJsonApiProxy(context.request);
