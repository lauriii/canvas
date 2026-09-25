import { getCookie } from 'h3';
import { isDraftSessionExpired, isPageRedirect } from '@drupal-canvas/headless';
import {
  createDraftServer,
  resolveDraftConfig,
} from '@drupal-canvas/headless/server';

import { createNuxtDraftAdapter, NUXT_DRAFT_FLAG_COOKIE_NAME } from './adapter';

import type {
  DraftData,
  EntityResult,
  PageResult,
} from '@drupal-canvas/headless';
import type { DraftConfig, DraftServer } from '@drupal-canvas/headless/server';
import type { JsonApiRuntimeConfig } from 'drupal-canvas/jsonapi-client';
import type { H3Event } from 'h3';

// One draft server per request event. Authentication and preview context
// come from its cookies and URL. This cache avoids re-deriving closures when
// several handlers ask for the server during one request.
const servers = new WeakMap<H3Event, DraftServer>();

/**
 * The draft server for one request event. Usable from any Nitro handler —
 * the module's own routes and the app's server routes alike.
 */
export function getDraftServer(event: H3Event): DraftServer {
  const existing = servers.get(event);
  if (existing) {
    return existing;
  }
  const server = createDraftServer({
    adapter: createNuxtDraftAdapter(event),
  });
  servers.set(event, server);
  return server;
}

/**
 * Whether draft mode is on for this request — the flag cookie, regardless
 * of whether the session data behind it is intact or expired. This is the
 * "should the app surface draft session state at all" signal (the banner);
 * for data access, getClient() already falls back to public content when
 * the session has expired.
 */
export function isDraftModeEnabled(event: H3Event): boolean {
  return getCookie(event, NUXT_DRAFT_FLAG_COOKIE_NAME) === '1';
}

/**
 * The draft session for this request, or null when draft mode is off or
 * the data cookie is missing or corrupt.
 */
export function getDraftData(event: H3Event): Promise<DraftData | null> {
  return getDraftServer(event).getDraftData();
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
  event: H3Event,
): ReturnType<DraftServer['getClient']> {
  return getDraftServer(event).getClient();
}

/** Fetches one entity by type and ID through the current draft session. */
export function fetchEntity(
  event: H3Event,
  options: Parameters<DraftServer['fetchEntity']>[0],
): Promise<EntityResult | null> {
  return getDraftServer(event).fetchEntity(options);
}

/**
 * Fetches a page by its Drupal path, resolved through Drupal's routing,
 * carrying the live draft session's bearer token when there is one.
 * An explicit previewContext replaces all URL-derived preview settings.
 */
export function fetchPage(
  event: H3Event,
  path: string,
  previewContext?: Parameters<DraftServer['fetchPage']>[1],
): Promise<PageResult | null> {
  return getDraftServer(event).fetchPage(path, previewContext);
}

/** Fetches one component preview through the current draft session. */
export function fetchComponentPreview(
  event: H3Event,
  componentId: string,
  previewUri?: string,
): Promise<PageResult | null> {
  return getDraftServer(event).fetchComponentPreview(componentId, previewUri);
}

/**
 * The nonsecret JSON:API runtime configuration for this request's browser
 * client (resolved endpoints, the proxy path, preview state). Serialize it
 * into a page or answer it from a server route; `createJsonApiClient()` from
 * `drupal-canvas/jsonapi-client` builds the browser client from it.
 */
export function getJsonApiRuntimeConfig(
  event: H3Event,
): Promise<JsonApiRuntimeConfig> {
  return getDraftServer(event).getJsonApiRuntimeConfig();
}

/**
 * The same-origin JSON:API proxy for this request; see the module's
 * `routes/jsonapi-proxy` handler.
 */
export function handleJsonApiProxy(
  event: H3Event,
  request: Request,
): Promise<Response> {
  return getDraftServer(event).handleJsonApiProxy(request);
}

export { isDraftSessionExpired, isPageRedirect, NUXT_DRAFT_FLAG_COOKIE_NAME };
export type {
  CanvasContext,
  EntityResult,
  DrupalRoute,
  DrupalRouteEntity,
  Page,
  PageHead,
  PageRedirect,
  PageResult,
} from '@drupal-canvas/headless';
export type { JsonApiRuntimeConfig };
