import { createHash, randomBytes } from 'crypto';
import http from 'http';
import axios from 'axios';

export const DEFAULT_CALLBACK_PORT = 4444;

export interface DiscoveredAuth {
  authorizationEndpoint: string;
  tokenEndpoint: string;
  clientId: string;
  /**
   * Scopes advertised by the resource server via scopes_supported in
   * /.well-known/oauth-protected-resource. Present (possibly empty) when the
   * field exists in the response; undefined when it was absent or on the 404
   * fallback path.
   */
  scopesSupported?: string[];
}

/**
 * Discovers OAuth endpoints and client ID from the Canvas site.
 *
 * 1. Fetches /.well-known/oauth-protected-resource with X-Canvas-CLI header.
 *    - authorization_servers[0] gives the auth server base URL.
 *    - X-Consumer-ID response header gives the client ID.
 * 2. Fetches /.well-known/openid-configuration from the auth server.
 * 3. If /.well-known/oauth-protected-resource returns 404, falls back to
 *    Simple OAuth's conventional endpoints on the site itself.
 */
export async function discoverAuth(
  siteUrl: string,
  clientIdOverride?: string,
): Promise<DiscoveredAuth> {
  const normalizedSiteUrl = siteUrl.replace(/\/+$/, '');

  let authServerBaseUrl: string;
  let clientId: string;
  let scopesSupported: string[] | undefined;

  try {
    const response = await axios.get(
      `${normalizedSiteUrl}/.well-known/oauth-protected-resource`,
      {
        headers: {
          'X-Canvas-CLI': '1',
          Accept: 'application/json',
        },
        timeout: 10000,
      },
    );

    const body = response.data as {
      authorization_servers?: string[];
      scopes_supported?: string[];
    };

    if (
      !body.authorization_servers ||
      body.authorization_servers.length === 0
    ) {
      throw new Error(
        'OAuth discovery response is missing authorization_servers.',
      );
    }

    authServerBaseUrl = body.authorization_servers[0];
    scopesSupported = body.scopes_supported;
    clientId =
      clientIdOverride ??
      (response.headers['x-consumer-id'] as string | undefined) ??
      (() => {
        throw new Error(
          'Client ID could not be discovered from X-Consumer-ID header. Use --client-id to provide it.',
        );
      })();
  } catch (error) {
    if (axios.isAxiosError(error)) {
      if (error.response?.status === 404) {
        // Fall back to Simple OAuth endpoints on the site itself.
        return {
          authorizationEndpoint: `${normalizedSiteUrl}/oauth/authorize`,
          tokenEndpoint: `${normalizedSiteUrl}/oauth/token`,
          clientId:
            clientIdOverride ??
            (() => {
              throw new Error(
                'Client ID could not be discovered automatically. Use --client-id to provide it.',
              );
            })(),
        };
      }
      if (error.response === undefined) {
        let message = `Could not reach ${normalizedSiteUrl}.\n\n`;
        if (normalizedSiteUrl.includes('ddev.site')) {
          message += 'Troubleshooting tips:\n';
          message += '  • Check if DDEV is running: ddev status\n';
          message += '  • Try HTTP instead of HTTPS\n';
          message += '  • Verify site is accessible in browser\n';
          message += '  • For HTTPS issues, try: ddev auth ssl';
        } else {
          message += 'Check your site URL and network connection.';
        }
        throw new Error(message);
      }
    }
    throw error;
  }

  // Discover auth server endpoints via OIDC metadata.
  const { authorizationEndpoint, tokenEndpoint } =
    await discoverAuthServerEndpoints(authServerBaseUrl);

  return { authorizationEndpoint, tokenEndpoint, clientId, scopesSupported };
}

async function discoverAuthServerEndpoints(
  authServerBaseUrl: string,
): Promise<{ authorizationEndpoint: string; tokenEndpoint: string }> {
  const base = authServerBaseUrl.replace(/\/+$/, '');

  const response = await axios.get(`${base}/.well-known/openid-configuration`, {
    timeout: 10000,
  });
  const body = response.data as {
    authorization_endpoint?: string;
    token_endpoint?: string;
  };

  if (body.authorization_endpoint && body.token_endpoint) {
    return {
      authorizationEndpoint: body.authorization_endpoint,
      tokenEndpoint: body.token_endpoint,
    };
  }

  throw new Error(
    `Could not discover OAuth endpoints for authorization server ${base}. ` +
      `Ensure /.well-known/openid-configuration is available.`,
  );
}

export function generateCodeVerifier(): string {
  // RFC 7636: 43–128 URL-safe chars.
  return randomBytes(32).toString('base64url').slice(0, 96);
}

export function deriveCodeChallenge(verifier: string): string {
  return createHash('sha256').update(verifier).digest('base64url');
}

export function generateState(): string {
  return randomBytes(16).toString('hex');
}

export interface CallbackResult {
  code: string;
  state: string;
}

/**
 * Starts a temporary HTTP server and waits for the OAuth callback.
 * Resolves with the authorization code and state on success.
 * Rejects if an error query parameter is received or after a 5-minute timeout.
 */
export function waitForCallback(port: number): Promise<CallbackResult> {
  return new Promise((resolve, reject) => {
    const server = http.createServer((req, res) => {
      const url = new URL(req.url ?? '/', `http://localhost:${String(port)}`);

      if (url.pathname !== '/callback') {
        res.writeHead(404);
        res.end();
        return;
      }

      const error = url.searchParams.get('error');
      if (error) {
        const description = url.searchParams.get('error_description') ?? error;
        res.writeHead(200, { 'Content-Type': 'text/html' });
        res.end(callbackHtml('Authorization failed', description));
        server.close();
        reject(new Error(`Authorization denied: ${description}`));
        return;
      }

      const code = url.searchParams.get('code');
      const state = url.searchParams.get('state');

      if (!code || !state) {
        res.writeHead(400, { 'Content-Type': 'text/html' });
        res.end(callbackHtml('Invalid callback', 'Missing code or state.'));
        server.close();
        reject(new Error('Invalid callback: missing code or state.'));
        return;
      }

      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end(callbackHtml('Login successful', 'You can close this tab.'));
      server.close();
      resolve({ code, state });
    });

    server.listen(port, '127.0.0.1', () => {
      // Server is ready.
    });

    server.on('error', (err) => {
      reject(
        new Error(
          `Could not start callback server on port ${String(port)}: ${err.message}`,
        ),
      );
    });

    // 5-minute timeout.
    const timeout = setTimeout(
      () => {
        server.close();
        reject(
          new Error('Timed out waiting for OAuth callback after 5 minutes.'),
        );
      },
      5 * 60 * 1000,
    );

    server.on('close', () => clearTimeout(timeout));
  });
}

function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function callbackHtml(heading: string, body: string): string {
  const h = escapeHtml(heading);
  const b = escapeHtml(body);
  return `<!DOCTYPE html><html><head><title>${h}</title></head><body><h1>${h}</h1><p>${b}</p></body></html>`;
}

export interface ExchangeCodeParams {
  tokenEndpoint: string;
  code: string;
  redirectUri: string;
  clientId: string;
  codeVerifier: string;
}

export interface TokenResponse {
  accessToken: string;
  refreshToken?: string;
  expiresIn?: number;
}

export async function exchangeCode(
  params: ExchangeCodeParams,
): Promise<TokenResponse> {
  const { tokenEndpoint, code, redirectUri, clientId, codeVerifier } = params;

  const body = new URLSearchParams({
    grant_type: 'authorization_code',
    code,
    redirect_uri: redirectUri,
    client_id: clientId,
    code_verifier: codeVerifier,
  });

  type TokenErrorBody = {
    error?: string;
    error_description?: string;
  };

  let response;
  try {
    response = await axios.post<
      {
        access_token: string;
        refresh_token?: string;
        expires_in?: number;
      } & TokenErrorBody
    >(tokenEndpoint, body.toString(), {
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      timeout: 10000,
    });
  } catch (error) {
    if (axios.isAxiosError(error) && error.response) {
      const data = error.response.data as TokenErrorBody;
      const description = data.error_description ?? data.error ?? error.message;
      throw new Error(`Token exchange failed: ${description}`);
    }
    throw error;
  }

  if (response.data.error) {
    const description = response.data.error_description ?? response.data.error;
    throw new Error(`Token exchange failed: ${description}`);
  }

  return {
    accessToken: response.data.access_token,
    refreshToken: response.data.refresh_token,
    expiresIn: response.data.expires_in,
  };
}
