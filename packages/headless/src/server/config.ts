import { resolveJsonApiBase, trimSlashes } from 'drupal-canvas/jsonapi-client';

/** The default same-origin path the application mounts the JSON:API proxy at. */
export const DEFAULT_JSONAPI_PROXY_PATH = '/api/canvas/jsonapi';

export interface DraftConfig {
  /**
   * Base URL of the Drupal site, without a trailing slash. Only the app's
   * *server* uses it; anything the editor's browser must reach on Drupal
   * (the standalone renew link) arrives as a signed assertion claim
   * instead, so multi-origin dev topologies need no second URL here.
   */
  baseUrl: string;
  /**
   * The site's JSON:API prefix, without slashes (e.g. `api` for a site
   * serving JSON:API at `/api`). Used only when the site-data endpoint is
   * unreachable; omit to fall back to the JSON:API client's `/jsonapi` default.
   */
  apiPrefix?: string;
  /**
   * An explicit full upstream JSON:API base URL (e.g.
   * `https://api.example.com/drupal/jsonapi`). Takes precedence over
   * discovery and `apiPrefix`. The Decoupled Router endpoint stays on
   * `baseUrl`.
   */
  jsonApiUrl?: string;
  /**
   * The site base URL (origin plus install path) of the site serving a
   * `jsonApiUrl` on another site, e.g. `https://api.example.com/mount` for
   * `https://api.example.com/mount/api`; a language prefix goes between it
   * and the JSON:API prefix. Without it such a JSON:API URL serves no
   * language-prefixed reads. `CANVAS_JSONAPI_SITE_URL`.
   */
  jsonApiSiteUrl?: string;
  /**
   * The same-origin path the application mounts the JSON:API proxy at.
   * Browser clients send every backend request through it.
   */
  jsonApiProxyPath?: string;
}

/**
 * Resolves the draft configuration from the environment, letting explicit
 * overrides win. CANVAS_SITE_URL is required unless overridden. The OAuth
 * client id is not configuration at all: the Canvas Headless module
 * provisions its consumer under a fixed id (see CANVAS_HEADLESS_CLIENT_ID
 * in ../constants).
 *
 * Optional environment variables: CANVAS_JSONAPI_PREFIX (fallback prefix),
 * CANVAS_JSONAPI_URL (full upstream JSON:API URL override), and
 * CANVAS_JSONAPI_PROXY_PATH (the application's proxy path).
 */
export function resolveDraftConfig(
  overrides: Partial<DraftConfig> = {},
): DraftConfig {
  const baseUrl = overrides.baseUrl ?? process.env.CANVAS_SITE_URL;

  if (!baseUrl) {
    throw new Error('CANVAS_SITE_URL must be set. See .env.example.');
  }

  const apiPrefix = trimSlashes(
    overrides.apiPrefix ?? process.env.CANVAS_JSONAPI_PREFIX ?? '',
  );
  const jsonApiUrl = (
    overrides.jsonApiUrl ??
    process.env.CANVAS_JSONAPI_URL ??
    ''
  ).replace(/\/+$/, '');
  const jsonApiSiteUrl = (
    overrides.jsonApiSiteUrl ??
    process.env.CANVAS_JSONAPI_SITE_URL ??
    ''
  ).replace(/\/+$/, '');
  if (jsonApiUrl) {
    // Validates the URL override against the site base URLs (throws with
    // the mismatch spelled out).
    resolveJsonApiBase(baseUrl, {
      apiUrl: jsonApiUrl,
      ...(jsonApiSiteUrl && { apiSiteUrl: jsonApiSiteUrl }),
    });
  }
  const jsonApiProxyPath =
    overrides.jsonApiProxyPath ??
    process.env.CANVAS_JSONAPI_PROXY_PATH ??
    DEFAULT_JSONAPI_PROXY_PATH;
  if (
    !jsonApiProxyPath.startsWith('/') ||
    jsonApiProxyPath.startsWith('//') ||
    jsonApiProxyPath.length < 2
  ) {
    throw new Error(
      'CANVAS_JSONAPI_PROXY_PATH must be a site-relative path such as /api/canvas/jsonapi.',
    );
  }

  return {
    baseUrl: baseUrl.replace(/\/+$/, ''),
    ...(apiPrefix && { apiPrefix }),
    ...(jsonApiUrl && { jsonApiUrl }),
    ...(jsonApiUrl && jsonApiSiteUrl && { jsonApiSiteUrl }),
    jsonApiProxyPath: jsonApiProxyPath.replace(/\/+$/, ''),
  };
}
