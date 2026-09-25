/**
 * @file
 * The SDK's JSON:API client factories. Both create the shared
 * `drupal-canvas` client implementation (the same one `useJsonApiClient()`
 * hands to React Code Components) instead of maintaining a separate
 * subclass: draft reads, serialization, and proxy mapping live there.
 */

import {
  createJsonApiClient,
  resolveJsonApiBase,
} from 'drupal-canvas/jsonapi-client';

import { getSessionToken } from '../token';

import type {
  CanvasJsonApiClient,
  JsonApiRuntimeConfig,
} from 'drupal-canvas/jsonapi-client';
import type { DraftData } from '../draft-data';
import type { DraftConfig } from './config';

/**
 * The resolved upstream JSON:API endpoints: the backend base URL (where the
 * supporting endpoints such as path translation live), the JSON:API prefix
 * relative to it, and — with a full URL override — the separate JSON:API base
 * URL.
 */
export interface JsonApiEndpoints {
  baseUrl: string;
  apiPrefix?: string;
  apiUrl?: string;
  apiSiteUrl?: string;
}

/**
 * Resolves the upstream JSON:API endpoints. An explicit full URL override
 * (`jsonApiUrl`) takes precedence over the discovered or configured prefix
 * for JSON:API requests only: the backend base URL keeps serving the
 * Decoupled Router and the other supporting endpoints, so an override on
 * another host or path never redirects path translation there.
 */
export function resolveJsonApiEndpoints(
  config: Pick<
    DraftConfig,
    'baseUrl' | 'apiPrefix' | 'jsonApiUrl' | 'jsonApiSiteUrl'
  >,
): JsonApiEndpoints {
  if (config.jsonApiUrl) {
    // Under the backend base URL the override is a prefix (a locale prefix
    // goes between the site path and it); elsewhere it is a foreign base,
    // localizable through its own site base URL.
    const api = resolveJsonApiBase(config.baseUrl, {
      apiUrl: config.jsonApiUrl,
      apiSiteUrl: config.jsonApiSiteUrl,
    });
    return {
      baseUrl: config.baseUrl,
      apiPrefix: api.apiPrefix,
      apiUrl: `${api.base}/${api.apiPrefix}`,
      ...(api.foreign && api.siteKnown && { apiSiteUrl: api.base }),
    };
  }
  return {
    baseUrl: config.baseUrl,
    ...(config.apiPrefix && { apiPrefix: config.apiPrefix }),
  };
}

/**
 * The full upstream JSON:API base URL the endpoints resolve to.
 */
export function resolveJsonApiUrl(endpoints: JsonApiEndpoints): string {
  return (
    endpoints.apiUrl ??
    `${endpoints.baseUrl.replace(/\/+$/, '')}/${endpoints.apiPrefix ?? 'jsonapi'}`
  );
}

/**
 * The nonsecret JSON:API runtime configuration for a browser client: the
 * resolved upstream endpoints, the application's proxy path, and the
 * session's resource version while a draft session is live. Contains no
 * credentials and is safe to serialize into a page.
 */
export function resolveJsonApiRuntimeConfig(
  config: Pick<
    DraftConfig,
    | 'baseUrl'
    | 'apiPrefix'
    | 'jsonApiUrl'
    | 'jsonApiSiteUrl'
    | 'jsonApiProxyPath'
  >,
  draftData: DraftData | null,
): JsonApiRuntimeConfig {
  const endpoints = resolveJsonApiEndpoints(config);
  const live = draftData !== null && getSessionToken(draftData) !== null;
  return {
    baseUrl: endpoints.baseUrl,
    ...(endpoints.apiPrefix && { apiPrefix: endpoints.apiPrefix }),
    ...(endpoints.apiUrl && { apiUrl: endpoints.apiUrl }),
    ...(endpoints.apiSiteUrl && { apiSiteUrl: endpoints.apiSiteUrl }),
    proxyUrl: config.jsonApiProxyPath ?? '/api/canvas/jsonapi',
    resourceVersion: live ? draftData.resourceVersion : null,
    preview: live,
  };
}

/**
 * A client for public content: unauthenticated, sees only published content.
 *
 * The config's apiPrefix (resolved from the site's canvasData.v0 payload by
 * the draft server) points the client at sites serving JSON:API from a
 * non-default prefix, e.g. `/api`; when absent, the client's `/jsonapi`
 * default applies.
 */
export function getPublicClient(
  config: Pick<
    DraftConfig,
    'baseUrl' | 'apiPrefix' | 'jsonApiUrl' | 'jsonApiSiteUrl'
  >,
): CanvasJsonApiClient {
  return createJsonApiClient({
    ...resolveJsonApiEndpoints(config),
    resourceVersion: null,
    preview: false,
    cacheScope: 'public',
  });
}

/**
 * A client for draft content, authenticated with the session's user-bound
 * access token (minted from the preview assertion, carrying the initiating
 * editor's own permissions). Returns working copies transparently: resource
 * reads use the session's resource version, and collection reads fetch each
 * item's working copy (see the shared client).
 *
 * Throws when the session has expired — callers are expected to check
 * isDraftSessionExpired() first and fall back to the public client with a
 * visible indicator instead of silently downgrading.
 */
export function getDraftClient(
  config: Pick<
    DraftConfig,
    'baseUrl' | 'apiPrefix' | 'jsonApiUrl' | 'jsonApiSiteUrl'
  >,
  draftData: DraftData,
): CanvasJsonApiClient {
  const token = getSessionToken(draftData);
  if (!token) {
    throw new Error('The draft preview session has expired.');
  }
  return createJsonApiClient({
    ...resolveJsonApiEndpoints(config),
    authentication: {
      type: 'Custom',
      credentials: { value: `${token.tokenType} ${token.value}` },
    },
    resourceVersion: draftData.resourceVersion,
    preview: true,
    // Cache entries are separated per editor session and token lifetime.
    cacheScope: `session:${draftData.sub}:${draftData.tokenExpiresAt}`,
  });
}
