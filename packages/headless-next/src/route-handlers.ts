// A namespace import: Next.js 15.0 lacks after, and then answers Drupal
// only after reading the tags.
import * as nextServer from 'next/server';
import {
  createCacheInvalidationHandler,
  createDraftServer,
} from '@drupal-canvas/headless/server';

import { nextDraftAdapter } from './adapter';
import { expireCanvasTags } from './cache';

import type { DraftConfig } from '@drupal-canvas/headless/server';

export interface DraftRouteHandlers {
  /** Mount at app/api/draft/route.ts. */
  draft: { GET: (request: Request) => Promise<Response> };
  /** Mount at app/api/draft/renew/route.ts. */
  draftRenew: { POST: (request: Request) => Promise<Response> };
  /**
   * Mount at app/api/disable-draft/route.ts. POST, not GET: exiting draft
   * mode changes state (it clears the session cookies), and a GET endpoint
   * reached by links would be eligible for prefetching — a framework or
   * browser prefetch could silently end the session.
   */
  disableDraft: { POST: () => Promise<Response> };
  /**
   * Mount at app/api/canvas/jsonapi/[[...path]]/route.ts (the default
   * CANVAS_JSONAPI_PROXY_PATH), exporting every method. The same-origin
   * JSON:API proxy portable Code Components reach Drupal through; see
   * createJsonApiProxyHandler() in @drupal-canvas/headless/server.
   */
  jsonApiProxy: {
    GET: (request: Request) => Promise<Response>;
    HEAD: (request: Request) => Promise<Response>;
    POST: (request: Request) => Promise<Response>;
    PATCH: (request: Request) => Promise<Response>;
    DELETE: (request: Request) => Promise<Response>;
    OPTIONS: (request: Request) => Promise<Response>;
  };
  /**
   * Mount at app/api/canvas/revalidate/route.ts, where Drupal announces
   * invalidated cache tags to its configured frontends. Expires the entries
   * cacheCanvasTags() tagged with them.
   */
  revalidate: { POST: (request: Request) => Promise<Response> };
}

/**
 * Creates the draft-mode route handlers: activation (redeems the
 * `?assertion=` preview URL), in-place renewal (POST `{assertion}`), exit,
 * the same-origin JSON:API proxy, and cache invalidation. Configuration defaults to
 * CANVAS_SITE_URL, resolved per request.
 */
export function createDraftRouteHandlers(
  config?: Partial<DraftConfig>,
): DraftRouteHandlers {
  const server = createDraftServer({ adapter: nextDraftAdapter, config });
  const proxy = (request: Request) => server.handleJsonApiProxy(request);
  return {
    draft: { GET: (request) => server.enableDraftMode(request) },
    draftRenew: { POST: (request) => server.renewDraftSession(request) },
    disableDraft: { POST: () => server.disableDraftMode() },
    jsonApiProxy: {
      GET: proxy,
      HEAD: proxy,
      POST: proxy,
      PATCH: proxy,
      DELETE: proxy,
      OPTIONS: proxy,
    },
    revalidate: {
      POST: createCacheInvalidationHandler({
        config,
        invalidate: expireCanvasTags,
        // Answer Drupal before reading the tags back from it.
        defer: nextServer.after,
      }),
    },
  };
}
