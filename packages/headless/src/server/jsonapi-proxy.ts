/**
 * @file
 * The same-origin JSON:API proxy for headless applications: forwards browser
 * requests from portable Code Components to the configured Drupal backend,
 * authenticating from the draft preview session rather than from anything the
 * browser supplies.
 *
 * The proxy forwards requests and response bodies without interpreting
 * JSON:API documents; draft reads (working copies) are the shared client's
 * job. What it does own:
 * - the backend and endpoint boundary: only the JSON:API prefix and the
 *   Decoupled Router endpoint on the configured backend, with redirects
 *   validated against the same boundary;
 * - session-driven authentication: no session forwards unauthenticated, a
 *   live session forwards the editor's user-bound token, an expired or
 *   invalid session answers a session error instead of public content;
 * - request hygiene: browser authentication headers and cookies are never
 *   forwarded, Drupal's response cookies are never returned, and
 *   state-changing requests must come from the application's own origin;
 * - cache safety: authenticated responses are private and never stored.
 *
 * Framework adapters mount this handler; it depends on neither React nor a
 * framework.
 *
 * @see docs/adr/0021-code-component-runtime-compatibility-across-frontend-modes.md
 */

import {
  JSONAPI_DRAFT_SESSION_EXPIRED_ERROR,
  JSONAPI_PROXY_SESSION_EXPIRED,
  JSONAPI_PROXY_SESSION_HEADER,
  mapJsonApiRequestToProxy,
  mapProxyPathToUpstream,
} from 'drupal-canvas/jsonapi-client';

import { getSessionToken } from '../token';
import { resolveJsonApiEndpoints } from './json-api-client';

import type { DraftData } from '../draft-data';
import type { DraftConfig } from './config';

export interface JsonApiProxyOptions {
  /** The resolved configuration (backend, prefix, proxy path). */
  getConfig: () => Promise<
    Pick<
      DraftConfig,
      | 'baseUrl'
      | 'apiPrefix'
      | 'jsonApiUrl'
      | 'jsonApiSiteUrl'
      | 'jsonApiProxyPath'
    >
  >;
  /** Whether the framework's draft flag is on for the current request. */
  isDraftModeEnabled: () => Promise<boolean>;
  /** The current request's draft session, or null. */
  getDraftData: () => Promise<DraftData | null>;
  /** Fetch implementation, injectable for tests. */
  fetchImpl?: typeof fetch;
}

/** Request headers the proxy forwards to Drupal. */
const FORWARDED_REQUEST_HEADERS = [
  'accept',
  'accept-language',
  'content-type',
  'if-match',
  'if-none-match',
  'if-modified-since',
];

/** Response headers the proxy returns to the browser. */
const FORWARDED_RESPONSE_HEADERS = [
  'content-type',
  'content-language',
  'content-disposition',
  'etag',
  'last-modified',
  'x-drupal-cache',
  'x-drupal-dynamic-cache',
];

const SAFE_METHODS = new Set(['GET', 'HEAD']);

/** Statuses whose Location header a client follows. */
const REDIRECT_STATUSES = new Set([301, 302, 303, 307, 308]);

/** Merges a `Vary` header value with additional header names. */
function mergeVary(upstream: string | null, ...names: string[]): string {
  const values = [...(upstream ?? '').split(','), ...names]
    .map((value) => value.trim())
    .filter(Boolean);
  if (values.includes('*')) {
    return '*';
  }
  const seen = new Set<string>();
  return values
    .filter((value) => {
      const key = value.toLowerCase();
      if (seen.has(key)) {
        return false;
      }
      seen.add(key);
      return true;
    })
    .join(', ');
}

const SUPPORTED_METHODS = ['GET', 'HEAD', 'POST', 'PATCH', 'DELETE'];

function problem(
  status: number,
  error: string,
  message: string,
  headers: Record<string, string> = {},
): Response {
  return Response.json(
    { error, message },
    {
      status,
      headers: { 'Cache-Control': 'private, no-store', ...headers },
    },
  );
}

/**
 * The application's own origin as the browser sees it, honoring reverse-proxy
 * forwarding headers.
 */
function requestOrigin(request: Request): string | null {
  const url = new URL(request.url);
  const forwardedHost = request.headers.get('x-forwarded-host');
  const host = forwardedHost?.split(',')[0].trim() || url.host;
  const forwardedProto = request.headers.get('x-forwarded-proto');
  const protocol = forwardedProto
    ? `${forwardedProto.split(',')[0].trim()}:`
    : url.protocol;
  if (!host) {
    return null;
  }
  return `${protocol}//${host}`.toLowerCase();
}

/**
 * Whether the request comes from the application's own origin: true, false,
 * or null when the request carries no signal (no `Sec-Fetch-Site` and no
 * `Origin` header, as for non-browser clients and same-origin GETs).
 */
export function isSameOriginRequest(request: Request): boolean | null {
  const fetchSite = request.headers.get('sec-fetch-site');
  if (fetchSite) {
    return fetchSite === 'same-origin' || fetchSite === 'none';
  }
  const origin = request.headers.get('origin');
  if (origin === null) {
    return null;
  }
  const own = requestOrigin(request);
  return own !== null && origin.toLowerCase() === own;
}

/**
 * Creates the proxy handler: a web `Request` in, a web `Response` out.
 */
export function createJsonApiProxyHandler(
  options: JsonApiProxyOptions,
): (request: Request) => Promise<Response> {
  const fetchImpl = options.fetchImpl ?? fetch;

  return async (request: Request): Promise<Response> => {
    const method = request.method.toUpperCase();
    if (method === 'OPTIONS') {
      return new Response(null, {
        status: 204,
        headers: {
          Allow: SUPPORTED_METHODS.join(', '),
          'Cache-Control': 'private, no-store',
        },
      });
    }
    if (!SUPPORTED_METHODS.includes(method)) {
      return problem(
        405,
        'method_not_allowed',
        `The JSON:API proxy does not support ${method} requests.`,
        { Allow: SUPPORTED_METHODS.join(', ') },
      );
    }

    // Browser access is restricted to the application's origin. Requests
    // that positively identify as cross-site are refused outright; for
    // state-changing requests a same-origin signal is required, so a
    // cross-site form post or a request without browser provenance can not
    // spend the session (CORS alone would not protect against either).
    const sameOrigin = isSameOriginRequest(request);
    if (
      sameOrigin === false ||
      (!SAFE_METHODS.has(method) && sameOrigin !== true)
    ) {
      return problem(
        403,
        'origin_not_allowed',
        'JSON:API proxy requests must originate from the application itself.',
      );
    }

    let config: Awaited<ReturnType<JsonApiProxyOptions['getConfig']>>;
    try {
      config = await options.getConfig();
    } catch (error) {
      return problem(
        500,
        'configuration_error',
        error instanceof Error ? error.message : String(error),
      );
    }
    const endpoints = resolveJsonApiEndpoints(config);
    const boundary = {
      baseUrl: endpoints.baseUrl,
      apiPrefix: endpoints.apiPrefix,
      apiUrl: endpoints.apiUrl,
      apiSiteUrl: endpoints.apiSiteUrl,
      proxyUrl: config.jsonApiProxyPath ?? '/api/canvas/jsonapi',
    };

    const requestUrl = new URL(request.url);
    const upstream = mapProxyPathToUpstream(requestUrl.pathname, boundary);
    if (upstream === null) {
      return problem(
        404,
        'endpoint_not_allowed',
        'The JSON:API proxy only serves the JSON:API and path-translation endpoints of the configured Drupal backend.',
      );
    }

    // Authentication comes from the session, never from the browser.
    const headers = new Headers();
    for (const name of FORWARDED_REQUEST_HEADERS) {
      const value = request.headers.get(name);
      if (value !== null) {
        headers.set(name, value);
      }
    }
    if (!headers.has('accept')) {
      headers.set('accept', 'application/vnd.api+json');
    }
    let authenticated = false;
    if (await options.isDraftModeEnabled()) {
      const draftData = await options.getDraftData();
      const token = draftData ? getSessionToken(draftData) : null;
      if (!token) {
        // Expired or invalid: never downgrade to public content silently.
        return problem(
          401,
          JSONAPI_DRAFT_SESSION_EXPIRED_ERROR,
          'The draft preview session has expired or is invalid. Renew the session from the Canvas editor, or open the preview from Drupal again.',
          { [JSONAPI_PROXY_SESSION_HEADER]: JSONAPI_PROXY_SESSION_EXPIRED },
        );
      }
      headers.set('authorization', `${token.tokenType} ${token.value}`);
      authenticated = true;
    }

    const hasBody = !SAFE_METHODS.has(method);
    let upstreamResponse: Response;
    try {
      upstreamResponse = await fetchImpl(
        `${upstream.url}${requestUrl.search}`,
        {
          method,
          headers,
          body: hasBody ? await request.arrayBuffer() : undefined,
          redirect: 'manual',
          cache: 'no-store',
        },
      );
    } catch (error) {
      return problem(
        502,
        'drupal_unreachable',
        `The Drupal backend could not be reached: ${
          error instanceof Error ? error.message : String(error)
        }`,
      );
    }

    // Drupal answering an authenticated request with 401 rejected the
    // session token (expired or revoked): surface it as a session error so
    // clients never mistake it for an ordinary item failure and fall back to
    // published content. 403 and other statuses are resource-level outcomes
    // and pass through.
    if (authenticated && upstreamResponse.status === 401) {
      return problem(
        401,
        JSONAPI_DRAFT_SESSION_EXPIRED_ERROR,
        'The Drupal backend rejected the draft preview session. Renew the session from the Canvas editor, or open the preview from Drupal again.',
        { [JSONAPI_PROXY_SESSION_HEADER]: JSONAPI_PROXY_SESSION_EXPIRED },
      );
    }

    const responseHeaders = new Headers();
    for (const name of FORWARDED_RESPONSE_HEADERS) {
      const value = upstreamResponse.headers.get(name);
      if (value !== null) {
        responseHeaders.set(name, value);
      }
    }
    // Responses depend on the absence of a session cookie, in addition to
    // whatever the backend varies on (for example Accept-Language).
    responseHeaders.set(
      'Vary',
      mergeVary(upstreamResponse.headers.get('vary'), 'Cookie'),
    );
    if (authenticated) {
      responseHeaders.set('Cache-Control', 'private, no-store');
    } else {
      const upstreamCacheControl =
        upstreamResponse.headers.get('cache-control');
      responseHeaders.set('Cache-Control', upstreamCacheControl ?? 'no-store');
    }

    const status = upstreamResponse.status;
    // 304 is a conditional-request answer, not a redirect: pass it through.
    if (status === 304) {
      return new Response(null, { status, headers: responseHeaders });
    }
    // Redirects stay within the same boundary and are rewritten to the proxy
    // so the browser follows them same-origin; anything else is refused.
    if (REDIRECT_STATUSES.has(status)) {
      const location = upstreamResponse.headers.get('location');
      let rewritten: string | null = null;
      if (location !== null) {
        try {
          const absolute = new URL(location, upstream.url).href;
          rewritten = mapJsonApiRequestToProxy(absolute, boundary);
        } catch {
          rewritten = null;
        }
      }
      if (rewritten === null) {
        return problem(
          502,
          'redirect_not_allowed',
          'The Drupal backend redirected outside the JSON:API proxy boundary.',
        );
      }
      responseHeaders.set('Location', rewritten);
      return new Response(null, { status, headers: responseHeaders });
    }

    return new Response(
      method === 'HEAD' || status === 204 ? null : upstreamResponse.body,
      { status, headers: responseHeaders },
    );
  };
}
