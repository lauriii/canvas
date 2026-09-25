/**
 * @file
 * Framework-agnostic URL mapping between a Drupal backend's JSON:API
 * endpoints and a same-origin application proxy. The browser client maps
 * upstream URLs to the proxy; the proxy (in the Canvas Headless SDK) validates
 * and maps them back. Both sides share this module so they cannot disagree
 * about which paths are supported.
 *
 * The supported endpoints are those the JSON:API client reaches:
 * - the JSON:API prefix (resources, collections, views, the index), and
 * - the Decoupled Router path translation endpoint (`getResourceByPath()`).
 * Both may be preceded by one Drupal language path prefix.
 *
 * @see docs/adr/0021-code-component-runtime-compatibility-across-frontend-modes.md
 */

/** The default JSON:API prefix of Drupal core's JSON:API module. */
export const DEFAULT_JSONAPI_PREFIX = 'jsonapi';

/** The default path of the Decoupled Router module's endpoint. */
export const DEFAULT_ROUTER_PREFIX = 'router/translate-path';

/**
 * The response header the proxy sets on draft-session errors, and the value
 * it carries when the session expired or is invalid.
 */
export const JSONAPI_PROXY_SESSION_HEADER = 'X-Canvas-Draft-Session';

export const JSONAPI_PROXY_SESSION_EXPIRED = 'expired';

/** The `error` code of the proxy's draft-session error body. */
export const JSONAPI_DRAFT_SESSION_EXPIRED_ERROR = 'draft_session_expired';

/** The endpoints the proxy maps. */
export interface JsonApiProxyEndpoints {
  /** JSON:API prefix without surrounding slashes, e.g. `jsonapi`. */
  apiPrefix: string;
  /** Decoupled Router path without surrounding slashes. */
  routerPrefix: string;
  /**
   * Absolute upstream JSON:API base URL when JSON:API is not served under the
   * backend base URL and `apiPrefix` (for example on another host or path).
   * Under the backend base URL it is a prefix override; elsewhere its site is
   * `apiSiteUrl` (or, without one, its origin) and the rest its prefix. The
   * supporting endpoints (path translation) stay under the backend base URL.
   */
  apiUrl?: string;
  /**
   * The site base URL (origin plus install path) of the site serving a
   * foreign `apiUrl`, for example `https://api.example/mount` for
   * `https://api.example/mount/api`: a locale prefix goes between it and the
   * JSON:API prefix (`https://api.example/mount/fr/api`). Without it a foreign
   * base accepts no locale prefix, since its site root is unknown.
   */
  apiSiteUrl?: string;
}

/** Where JSON:API requests go, resolved from the endpoint configuration. */
export interface ResolvedJsonApiBase {
  /**
   * The absolute base URL (origin plus any site path) a locale prefix and
   * the JSON:API prefix are appended to, without a trailing slash.
   */
  base: string;
  /** The JSON:API prefix relative to `base`, without surrounding slashes. */
  apiPrefix: string;
  /** Whether JSON:API lives on another site than the backend base URL. */
  foreign: boolean;
  /**
   * Whether `base` is a site root (so a locale path prefix can be applied):
   * always for the backend site, for a foreign base only with `apiSiteUrl`.
   */
  siteKnown: boolean;
}

/**
 * Resolves the JSON:API base and prefix. A full URL override under the
 * backend base URL is a prefix override (a locale prefix goes between the
 * site path and the prefix, as Drupal serves it); an override elsewhere is a
 * foreign base whose path is the prefix.
 */
export function resolveJsonApiBase(
  baseUrl: string,
  endpoints: Partial<JsonApiProxyEndpoints> = {},
): ResolvedJsonApiBase {
  const site = normalizeBaseUrl(baseUrl);
  if (endpoints.apiUrl) {
    const api = normalizeBaseUrl(endpoints.apiUrl);
    if (api === site || api.startsWith(`${site}/`)) {
      return {
        base: site,
        apiPrefix:
          trimSlashes(api.slice(site.length)) || DEFAULT_JSONAPI_PREFIX,
        foreign: false,
        siteKnown: true,
      };
    }
    if (endpoints.apiSiteUrl) {
      const apiSite = normalizeBaseUrl(endpoints.apiSiteUrl);
      if (api !== apiSite && !api.startsWith(`${apiSite}/`)) {
        throw new Error(
          `[drupal-canvas] The JSON:API URL (${api}) is not under its site base URL (${apiSite}).`,
        );
      }
      return {
        base: apiSite,
        apiPrefix:
          trimSlashes(api.slice(apiSite.length)) || DEFAULT_JSONAPI_PREFIX,
        foreign: true,
        siteKnown: true,
      };
    }
    const url = new URL(api);
    return {
      base: url.origin,
      apiPrefix: trimSlashes(url.pathname) || DEFAULT_JSONAPI_PREFIX,
      foreign: true,
      siteKnown: false,
    };
  }
  return {
    base: site,
    apiPrefix: trimSlashes(endpoints.apiPrefix || DEFAULT_JSONAPI_PREFIX),
    foreign: false,
    siteKnown: true,
  };
}

export type JsonApiProxyEndpointName = 'jsonapi' | 'router';

/** A validated proxied path. */
export interface ResolvedJsonApiProxyPath {
  endpoint: JsonApiProxyEndpointName;
  /** The site-relative upstream path, including any language prefix. */
  path: string;
}

/**
 * A possible language prefix is one safe path segment, not a language code.
 * Drupal permits custom text (but no slash); this is not a configuration lookup.
 * Reject ambiguous escapes and URL delimiters before allowing an extra segment
 * in front of the configured endpoint. Never decode the forwarded path itself.
 */
function isSafeOptionalPrefix(segment: string): boolean {
  try {
    const decoded = decodeURIComponent(segment);
    return (
      decoded !== '.' && decoded !== '..' && !/[/\\%?#\p{Cc}]/u.test(decoded)
    );
  } catch {
    return false;
  }
}

/** Strips surrounding slashes. */
export function trimSlashes(value: string): string {
  return value.replace(/^\/+|\/+$/g, '');
}

function splitSegments(prefix: string): string[] {
  return trimSlashes(prefix).split('/').filter(Boolean);
}

/**
 * Whether `path` is a safe site-relative path: one leading slash, no empty,
 * dot, or dot-dot segments, no backslashes, and no encoded slashes that could
 * decode into a different path upstream.
 */
export function isSafeProxyPath(path: string): boolean {
  if (!path.startsWith('/') || path.startsWith('//') || path.includes('\\')) {
    return false;
  }
  if (/%2f|%5c/i.test(path)) {
    return false;
  }
  const segments = path.slice(1).split('/');
  return segments.every(
    (segment, index) =>
      (segment !== '' || index === segments.length - 1) &&
      segment !== '.' &&
      segment !== '..',
  );
}

function matchesPrefix(segments: string[], prefix: string[]): boolean {
  return (
    prefix.length > 0 &&
    segments.length >= prefix.length &&
    prefix.every((segment, index) => segments[index] === segment)
  );
}

/**
 * Validates a site-relative path against the supported endpoints. Returns the
 * endpoint it belongs to, or `null` when the path is unsafe or outside the
 * boundary.
 */
export function resolveJsonApiProxyPath(
  path: string,
  endpoints: Partial<JsonApiProxyEndpoints> = {},
): ResolvedJsonApiProxyPath | null {
  if (!isSafeProxyPath(path)) {
    return null;
  }
  const apiPrefix = splitSegments(
    endpoints.apiPrefix || DEFAULT_JSONAPI_PREFIX,
  );
  const routerPrefix = splitSegments(
    endpoints.routerPrefix || DEFAULT_ROUTER_PREFIX,
  );
  const segments = path.slice(1).split('/').filter(Boolean);
  const candidates = [segments];
  if (segments.length > 1 && isSafeOptionalPrefix(segments[0])) {
    candidates.push(segments.slice(1));
  }
  for (const candidate of candidates) {
    if (matchesPrefix(candidate, apiPrefix)) {
      return { endpoint: 'jsonapi', path };
    }
    if (matchesPrefix(candidate, routerPrefix)) {
      return { endpoint: 'router', path };
    }
  }
  return null;
}

/** Normalizes a backend base URL: absolute, no trailing slash. */
export function normalizeBaseUrl(baseUrl: string): string {
  const url = new URL(baseUrl);
  url.search = '';
  url.hash = '';
  return `${url.origin}${url.pathname.replace(/\/+$/, '')}`;
}

/**
 * Splits an absolute upstream URL into the part relative to the backend base
 * URL. Returns `null` when the URL is not on the backend.
 */
export function toBackendRelativePath(
  url: string,
  baseUrl: string,
): { path: string; search: string } | null {
  let target: URL;
  try {
    target = new URL(url);
  } catch {
    return null;
  }
  const base = new URL(normalizeBaseUrl(baseUrl));
  if (target.origin !== base.origin) {
    return null;
  }
  const basePath = base.pathname.replace(/\/+$/, '');
  if (
    basePath !== '' &&
    target.pathname !== basePath &&
    !target.pathname.startsWith(`${basePath}/`)
  ) {
    return null;
  }
  const path = target.pathname.slice(basePath.length) || '/';
  return { path, search: target.search };
}

/**
 * Maps an absolute upstream JSON:API URL to the same-origin proxy. Returns
 * `null` for URLs that are not on the backend. Throws for backend URLs
 * outside the supported endpoints, so an absolute Drupal URL can never bypass
 * the proxy.
 */
export function mapJsonApiRequestToProxy(
  url: string,
  options: {
    baseUrl: string;
    proxyUrl: string;
  } & Partial<JsonApiProxyEndpoints>,
): string | null {
  const api = resolveJsonApiBase(options.baseUrl, options);
  const site = normalizeBaseUrl(options.baseUrl);
  const endpoints = {
    apiPrefix: api.apiPrefix,
    routerPrefix: options.routerPrefix,
  };
  // With a foreign JSON:API base, JSON:API requests live there and the
  // supporting endpoints on the backend base URL. Otherwise both are
  // relative to the backend base URL (a same-site override is a prefix).
  const targets: Array<{
    base: string;
    endpoint: JsonApiProxyEndpointName | null;
  }> = api.foreign
    ? [
        { base: api.base, endpoint: 'jsonapi' },
        { base: site, endpoint: 'router' },
      ]
    : [{ base: site, endpoint: null }];
  let origin: string;
  try {
    origin = new URL(url).origin;
  } catch {
    return null;
  }
  // Any URL on a backend origin is the backend's: outside the site path or
  // the supported endpoints it must never leave through the proxy unmapped.
  let onBackend = false;
  for (const target of targets) {
    if (new URL(target.base).origin === origin) {
      onBackend = true;
    }
    const relative = toBackendRelativePath(url, target.base);
    if (relative === null) {
      continue;
    }
    const resolved = resolveJsonApiProxyPath(relative.path, endpoints);
    if (
      resolved !== null &&
      (target.endpoint === null || resolved.endpoint === target.endpoint) &&
      // Without a known foreign site root, no optional segment may precede
      // its configured API mount (the direct client enforces the same rule).
      (resolved.endpoint !== 'jsonapi' ||
        api.siteKnown ||
        matchesPrefix(
          splitSegments(resolved.path),
          splitSegments(api.apiPrefix),
        ))
    ) {
      return `${options.proxyUrl.replace(/\/+$/, '')}${resolved.path}${relative.search}`;
    }
  }
  if (!onBackend) {
    return null;
  }
  throw new Error(
    `[drupal-canvas] The browser JSON:API client only reaches the Drupal ` +
      `backend through the application proxy, and "${url}" is ` +
      'not a supported JSON:API or path-translation endpoint.',
  );
}

/**
 * Maps a proxied request path back to its absolute upstream URL. Returns
 * `null` when the path is unsafe or outside the supported endpoints.
 */
export function mapProxyPathToUpstream(
  proxiedPath: string,
  options: {
    baseUrl: string;
    proxyUrl: string;
  } & Partial<JsonApiProxyEndpoints>,
): { url: string; endpoint: JsonApiProxyEndpointName } | null {
  const proxyPath = new URL(
    options.proxyUrl,
    'http://localhost',
  ).pathname.replace(/\/+$/, '');
  if (proxyPath !== '' && !proxiedPath.startsWith(`${proxyPath}/`)) {
    return null;
  }
  const path =
    proxyPath === '' ? proxiedPath : proxiedPath.slice(proxyPath.length);
  const api = resolveJsonApiBase(options.baseUrl, options);
  const resolved = resolveJsonApiProxyPath(path, {
    apiPrefix: api.apiPrefix,
    routerPrefix: options.routerPrefix,
  });
  if (
    resolved === null ||
    (resolved.endpoint === 'jsonapi' &&
      !api.siteKnown &&
      !matchesPrefix(
        splitSegments(resolved.path),
        splitSegments(api.apiPrefix),
      ))
  ) {
    return null;
  }
  const upstreamBase =
    resolved.endpoint === 'jsonapi'
      ? api.base
      : normalizeBaseUrl(options.baseUrl);
  return {
    url: `${upstreamBase}${resolved.path}`,
    endpoint: resolved.endpoint,
  };
}
