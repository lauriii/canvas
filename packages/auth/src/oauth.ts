/**
 * Shared OAuth token-endpoint helpers used by the Canvas CLI and the Canvas
 * Workbench dev server. Both speak to the same Drupal site via the same
 * `simple_oauth` flows, so they share these helpers to avoid drift.
 */

export interface OauthTokenResponse {
  accessToken: string;
  refreshToken?: string;
  /** Lifetime in seconds, when reported by the OAuth server. */
  expiresIn?: number;
  /**
   * Wall-clock expiry derived from `expires_in` at the time of the request,
   * in milliseconds since the Unix epoch.
   */
  expiresAt?: number;
}

export class OauthError extends Error {
  readonly status?: number;
  /**
   * The response body. When the server returned JSON, this is the parsed
   * object; when it returned non-JSON or no body, this is the raw string
   * (or undefined for transport-level failures).
   */
  readonly body?: unknown;

  constructor(
    message: string,
    options: { status?: number; body?: unknown; cause?: unknown } = {},
  ) {
    super(
      message,
      options.cause === undefined ? undefined : { cause: options.cause },
    );
    this.name = 'OauthError';
    this.status = options.status;
    this.body = options.body;
  }

  /** OAuth `error` field from a structured error body, if present. */
  get errorCode(): string | null {
    return this.readBodyString('error');
  }

  /** OAuth `error_description` field from a structured error body, if present. */
  get errorDescription(): string | null {
    return this.readBodyString('error_description');
  }

  /** OAuth `hint` field from a structured error body, if present. */
  get errorHint(): string | null {
    return this.readBodyString('hint');
  }

  private readBodyString(key: string): string | null {
    if (!this.body || typeof this.body !== 'object') return null;
    const value = (this.body as Record<string, unknown>)[key];
    return typeof value === 'string' && value ? value : null;
  }
}

export interface OauthRequestOptions {
  /**
   * Custom fetch implementation. Defaults to the global `fetch`. Tests can
   * pass a mock; production callers should not need to set this.
   */
  fetchImpl?: typeof fetch;
}

/**
 * POSTs `body` to an OAuth token endpoint and returns the normalized response.
 *
 * Throws `OauthError` on transport failures, non-2xx responses, malformed
 * bodies, or RFC 6749 error payloads (`{"error": "..."}`).
 */
export async function requestOauthToken(
  url: string,
  body: URLSearchParams,
  options: OauthRequestOptions = {},
): Promise<OauthTokenResponse> {
  const fetchImpl = options.fetchImpl ?? fetch;
  let response: Response;
  try {
    response = await fetchImpl(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    });
  } catch (cause) {
    throw new OauthError(
      `OAuth token request to ${url} failed: ${cause instanceof Error ? cause.message : String(cause)}`,
      { cause },
    );
  }

  if (!response.ok) {
    const text = await response.text().catch(() => '');
    // Attempt to parse JSON so consumers can read structured OAuth fields
    // (`error`, `error_description`, `hint`) directly off the error.
    let parsedBody: unknown = text;
    if (text) {
      try {
        parsedBody = JSON.parse(text);
      } catch {
        // Fall through with the raw text body.
      }
    }
    throw new OauthError(
      `OAuth token request to ${url} failed (${response.status}): ${text}`,
      { status: response.status, body: parsedBody },
    );
  }

  let data: unknown;
  try {
    data = await response.json();
  } catch (cause) {
    throw new OauthError('OAuth token response was not valid JSON', { cause });
  }
  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    throw new OauthError('OAuth token response was not a JSON object', {
      body: data,
    });
  }
  const obj = data as Record<string, unknown>;

  // Some OAuth servers signal errors with HTTP 200 + `{"error": "..."}`.
  // Surface those the same way as a 4xx.
  if (typeof obj.error === 'string') {
    const description =
      typeof obj.error_description === 'string' ? obj.error_description : null;
    throw new OauthError(description ?? obj.error, { body: data });
  }

  const accessToken = obj.access_token;
  if (typeof accessToken !== 'string' || accessToken.length === 0) {
    throw new OauthError('OAuth token response missing `access_token`', {
      body: data,
    });
  }
  const expiresIn =
    typeof obj.expires_in === 'number' ? obj.expires_in : undefined;
  return {
    accessToken,
    refreshToken:
      typeof obj.refresh_token === 'string' ? obj.refresh_token : undefined,
    expiresIn,
    expiresAt:
      expiresIn === undefined ? undefined : Date.now() + expiresIn * 1000,
  };
}

/**
 * Exchanges a refresh token for a new access token via the OAuth
 * `refresh_token` grant. Throws `OauthError` on failure.
 *
 * Note: `simple_oauth` rotates refresh tokens by default, so callers must
 * persist the returned `refreshToken` (when present) and use it for the next
 * refresh — otherwise subsequent refreshes will fail.
 */
export async function refreshAccessToken(
  params: {
    tokenEndpoint: string;
    refreshToken: string;
    clientId: string;
  },
  options: OauthRequestOptions = {},
): Promise<OauthTokenResponse> {
  return requestOauthToken(
    params.tokenEndpoint,
    new URLSearchParams({
      grant_type: 'refresh_token',
      refresh_token: params.refreshToken,
      client_id: params.clientId,
    }),
    options,
  );
}

/**
 * Requests a new access token via the OAuth `client_credentials` grant
 * (machine-to-machine authentication). Throws `OauthError` on failure.
 */
export async function fetchClientCredentialsToken(
  params: {
    tokenEndpoint: string;
    clientId: string;
    clientSecret: string;
    scope: string;
  },
  options: OauthRequestOptions = {},
): Promise<OauthTokenResponse> {
  return requestOauthToken(
    params.tokenEndpoint,
    new URLSearchParams({
      grant_type: 'client_credentials',
      client_id: params.clientId,
      client_secret: params.clientSecret,
      scope: params.scope,
    }),
    options,
  );
}

/**
 * Returns `${siteUrl}/oauth/token`, normalizing trailing slashes on the
 * site URL.
 */
export function defaultTokenEndpoint(siteUrl: string): string {
  return `${siteUrl.replace(/\/+$/, '')}/oauth/token`;
}
