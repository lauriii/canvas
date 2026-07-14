import { CANVAS_HEADLESS_CLIENT_ID, JWT_BEARER_GRANT_TYPE } from '../constants';

import type { DraftConfig } from './config';

/**
 * The raw outcome of presenting an assertion at Drupal's token endpoint.
 * Framework-free by design: the draft flows dress failures as HTTP
 * responses, the assertion verifier maps them to status codes — both from
 * this one result shape.
 */
export type AssertionExchangeResult =
  | {
      ok: true;
      tokenType: string;
      accessToken: string;
      /** Token lifetime in seconds, as reported by the token endpoint. */
      expiresIn: number;
    }
  | {
      ok: false;
      /** 'network' when Drupal was unreachable, 'upstream' when it refused. */
      kind: 'network' | 'upstream';
      /** The upstream HTTP status; absent for network failures. */
      status?: number;
      message: string;
    };

export interface AssertionExchangePkce {
  /**
   * The S256 challenge to register for the session's *next* renewal
   * exchange; Drupal stores it against the session. Optional on Drupal's
   * side — an exchange that registers none simply cannot renew in place.
   */
  codeChallenge?: string;
  /**
   * The verifier matching the challenge registered at the *previous*
   * redemption. Required by Drupal for renewal assertions, which transit
   * the embedded page's script context; activation assertions carry no
   * proof requirement.
   */
  codeVerifier?: string;
}

/**
 * Exchanges a preview assertion at Drupal's standard token endpoint (RFC
 * 7523 jwt-bearer grant). Drupal verifies the signature, expiry, and
 * single-use jti, and answers with an access token bound to the editor who
 * initiated the preview. No client secret is involved — the consumer is a
 * public client and the assertion itself is the credential.
 */
export async function exchangeAssertion(
  assertion: string,
  config: Pick<DraftConfig, 'baseUrl'>,
  fetchImpl: typeof fetch = fetch,
  pkce: AssertionExchangePkce = {},
): Promise<AssertionExchangeResult> {
  let tokenResponse: Response;
  try {
    tokenResponse = await fetchImpl(`${config.baseUrl}/oauth/token`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        Accept: 'application/json',
      },
      body: new URLSearchParams({
        grant_type: JWT_BEARER_GRANT_TYPE,
        assertion,
        client_id: CANVAS_HEADLESS_CLIENT_ID,
        ...(pkce.codeChallenge
          ? {
              code_challenge: pkce.codeChallenge,
              code_challenge_method: 'S256',
            }
          : {}),
        ...(pkce.codeVerifier ? { code_verifier: pkce.codeVerifier } : {}),
      }).toString(),
      cache: 'no-store',
    });
  } catch {
    return {
      ok: false,
      kind: 'network',
      message: 'Could not reach Drupal to redeem the preview assertion.',
    };
  }

  if (!tokenResponse.ok) {
    const body = (await tokenResponse.json().catch(() => null)) as {
      error?: string;
      error_description?: string;
      hint?: string;
    } | null;
    const message = [body?.error_description, body?.hint]
      .filter(Boolean)
      .join(' ');
    return {
      ok: false,
      kind: 'upstream',
      status: tokenResponse.status,
      message: message || 'Invalid preview assertion.',
    };
  }

  const tokenBody = (await tokenResponse.json()) as {
    token_type: string;
    expires_in: number;
    access_token: string;
  };
  return {
    ok: true,
    tokenType: tokenBody.token_type,
    accessToken: tokenBody.access_token,
    expiresIn: tokenBody.expires_in,
  };
}
