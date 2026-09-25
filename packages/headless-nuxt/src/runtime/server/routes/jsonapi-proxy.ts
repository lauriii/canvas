import { defineEventHandler, toWebRequest } from 'h3';

import { getDraftServer } from '../session';

/**
 * The same-origin JSON:API proxy portable Code Components reach Drupal
 * through, authenticated from the draft session. Mounted at
 * /api/canvas/jsonapi/** (the default CANVAS_JSONAPI_PROXY_PATH) by the
 * module, for every method; see createJsonApiProxyHandler() in
 * @drupal-canvas/headless/server.
 */
export default defineEventHandler((event) =>
  getDraftServer(event).handleJsonApiProxy(toWebRequest(event)),
);
