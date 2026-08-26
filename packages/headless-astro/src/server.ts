import { isDraftSessionExpired } from '@drupal-canvas/headless';
import {
  createDraftServer,
  resolveDraftConfig,
} from '@drupal-canvas/headless/server';

import {
  ASTRO_DRAFT_FLAG_COOKIE_NAME,
  createAstroDraftAdapter,
} from './adapter';

import type { DraftData } from '@drupal-canvas/headless';
import type {
  DraftConfig,
  DraftServer,
  PageResult,
} from '@drupal-canvas/headless/server';
import type { AstroDraftContext } from './adapter';

// One draft server per request context. All state lives in the request's
// cookies, so this cache is purely about not re-deriving the closures when
// a page and its components each ask for the server.
const servers = new WeakMap<AstroCookies, DraftServer>();

type AstroCookies = AstroDraftContext['cookies'];

/**
 * The draft server for one request. Pass the `Astro` global (components)
 * or the APIContext (endpoints): anything carrying the request's cookies
 * and redirect.
 */
export function getDraftServer(context: AstroDraftContext): DraftServer {
  const existing = servers.get(context.cookies);
  if (existing) {
    return existing;
  }
  const server = createDraftServer({
    adapter: createAstroDraftAdapter(context),
  });
  servers.set(context.cookies, server);
  return server;
}

/**
 * Whether draft mode is on for this request — the flag cookie, regardless
 * of whether the session data behind it is intact or expired. This is the
 * "should the app surface draft session state at all" signal (the banner);
 * for data access, getClient() already falls back to public content when
 * the session has expired.
 */
export function isDraftModeEnabled(context: AstroDraftContext): boolean {
  return context.cookies.get(ASTRO_DRAFT_FLAG_COOKIE_NAME)?.value === '1';
}

/**
 * The draft session for this request, or null when draft mode is off or
 * the data cookie is missing or corrupt.
 */
export function getDraftData(
  context: AstroDraftContext,
): Promise<DraftData | null> {
  return getDraftServer(context).getDraftData();
}

/**
 * The resolved configuration (environment-driven; see DraftConfig).
 */
export function getDraftConfig(): DraftConfig {
  return resolveDraftConfig();
}

/**
 * The right JSON:API client for this request: draft-session-authenticated
 * while the session is live, anonymous otherwise. The type is derived from
 * the draft server so this package needs no dependency on the JSON:API
 * client library itself.
 */
export function getClient(
  context: AstroDraftContext,
): ReturnType<DraftServer['getClient']> {
  return getDraftServer(context).getClient();
}

/**
 * Fetches a page by its Drupal path, resolved through Drupal's routing,
 * carrying the live draft session's bearer token when there is one.
 */
export function fetchPage(
  context: AstroDraftContext,
  path: string,
): Promise<PageResult | null> {
  return getDraftServer(context).fetchPage(path);
}

/** Fetches one component preview through the current draft session. */
export function fetchComponentPreview(
  context: AstroDraftContext,
  componentId: string,
): Promise<PageResult | null> {
  return getDraftServer(context).fetchComponentPreview(componentId);
}

export { isDraftSessionExpired };
