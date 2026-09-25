import {
  disableDraftMode,
  enableDraftMode,
  handleJsonApiProxy,
  renewDraftSession,
} from './server';

/**
 * The shape a TanStack Start server route handler takes: the framework
 * hands the handler a context with the web Request and expects a web
 * Response back. Typed structurally so this package does not depend on
 * the router's route types.
 */
type ServerRouteHandler = (context: { request: Request }) => Promise<Response>;

export interface DraftRouteHandlers {
  /** Mount at src/routes/api/draft.ts as the GET handler. */
  draft: { GET: ServerRouteHandler };
  /** Mount at src/routes/api/draft.renew.ts as the POST handler. */
  draftRenew: { POST: ServerRouteHandler };
  /**
   * Mount at src/routes/api/disable-draft.ts as the POST handler. POST,
   * not GET: exiting draft mode changes state (it clears the session
   * cookies), and a GET endpoint reached by links would be eligible for
   * prefetching — a framework or browser prefetch could silently end the
   * session.
   */
  disableDraft: { POST: ServerRouteHandler };
  /**
   * Mount at src/routes/api/canvas.jsonapi.$.ts (the default
   * CANVAS_JSONAPI_PROXY_PATH) with every method. The same-origin JSON:API
   * proxy portable Code Components reach Drupal through; see
   * createJsonApiProxyHandler() in @drupal-canvas/headless/server.
   */
  jsonApiProxy: {
    GET: ServerRouteHandler;
    HEAD: ServerRouteHandler;
    POST: ServerRouteHandler;
    PATCH: ServerRouteHandler;
    /** Routed to the shared proxy's 405 response, never forwarded. */
    PUT: ServerRouteHandler;
    DELETE: ServerRouteHandler;
    OPTIONS: ServerRouteHandler;
  };
}

/**
 * Creates the draft-mode route handlers: activation (redeems the
 * `?assertion=` preview URL), in-place renewal (POST `{assertion}`), exit,
 * and the same-origin JSON:API proxy. Configuration defaults to
 * CANVAS_SITE_URL, resolved per request.
 *
 * ```ts
 * // src/routes/api/draft.ts
 * import { createFileRoute } from '@tanstack/react-router';
 * import { createDraftRouteHandlers } from '@drupal-canvas/headless-tanstack-start';
 *
 * const { draft } = createDraftRouteHandlers();
 * export const Route = createFileRoute('/api/draft')({
 *   server: { handlers: { GET: draft.GET } },
 * });
 * ```
 */
export function createDraftRouteHandlers(): DraftRouteHandlers {
  const proxy: ServerRouteHandler = ({ request }) =>
    handleJsonApiProxy(request);
  return {
    draft: { GET: ({ request }) => enableDraftMode(request) },
    draftRenew: { POST: ({ request }) => renewDraftSession(request) },
    disableDraft: { POST: () => disableDraftMode() },
    jsonApiProxy: {
      GET: proxy,
      HEAD: proxy,
      POST: proxy,
      PATCH: proxy,
      PUT: proxy,
      DELETE: proxy,
      OPTIONS: proxy,
    },
  };
}
