import { exchangeAssertion } from './token-exchange';

import type { DraftConfig } from './config';

export type AssertionVerification =
  | { ok: true }
  | { ok: false; status: 401 | 403 | 502; message: string };

/**
 * Verifies that a request comes from the embedding Drupal Canvas instance,
 * by proof-by-redemption: the assertion is presented at Drupal's own token
 * endpoint, and acceptance there proves it was minted by that Drupal, for a
 * user holding the preview permission — signature, 60 s expiry, and
 * single-use jti are all enforced on Drupal's side. The app needs no key
 * material and no shared secret; the assertion is the credential.
 *
 * The verification is stateless and the minted access token is a byproduct:
 * discarded here, never returned, logged, or stored. Assertions are
 * single-use, so every call must present a freshly minted one — a replay
 * fails the exchange and verifies as 401.
 *
 * Unlike the draft-session redemption, the assertion's session claims
 * (path, resourceVersion, renewUrl) are irrelevant here: only the exchange
 * outcome matters.
 */
export async function verifyAssertionByRedemption(
  assertion: string,
  config: Pick<DraftConfig, 'baseUrl'>,
  fetchImpl: typeof fetch = fetch,
): Promise<AssertionVerification> {
  const exchange = await exchangeAssertion(assertion, config, fetchImpl);

  if (exchange.ok) {
    return { ok: true };
  }

  if (exchange.kind === 'network') {
    return {
      ok: false,
      status: 502,
      message: 'Could not reach Drupal to verify the assertion.',
    };
  }

  // OAuth refusals (invalid_grant, invalid_request — upstream 400/401) mean
  // the credential did not verify: 401 for the caller. An upstream 403
  // passes through; anything else is an upstream contract violation, not a
  // caller error. The message strings come from Drupal itself, so
  // forwarding them to a caller that must hold Drupal editing rights leaks
  // nothing.
  if (exchange.status === 400 || exchange.status === 401) {
    return { ok: false, status: 401, message: exchange.message };
  }
  if (exchange.status === 403) {
    return { ok: false, status: 403, message: exchange.message };
  }
  return {
    ok: false,
    status: 502,
    message: 'Unexpected response from Drupal while verifying the assertion.',
  };
}
