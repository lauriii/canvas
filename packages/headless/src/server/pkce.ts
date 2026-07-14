/**
 * @file
 * PKCE pair binding assertion redemption to the app server (RFC 7636
 * shapes).
 *
 * Renewal assertions reach the app relayed through the embedded page's
 * script context (host → postMessage → client → renew endpoint), so a
 * script injected into the app could intercept one. Drupal's grant
 * therefore refuses to redeem a renewal assertion unless the request also
 * proves possession of the running session: a `code_verifier` hashing to
 * the `code_challenge` the app server registered at the previous
 * redemption. The verifier never leaves the server — it lives in the
 * httpOnly draft data cookie — so an intercepted assertion is worthless on
 * its own.
 *
 * Every redemption registers a fresh challenge for the next one; the
 * verifier is stored alongside the session and rotated with it.
 *
 * Built on Web Crypto, not node:crypto, keeping the server entry edge-safe.
 */

function base64url(bytes: Uint8Array): string {
  return btoa(String.fromCharCode(...bytes))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
}

/** Generates a fresh, high-entropy code verifier. */
export function generateCodeVerifier(): string {
  // 32 random bytes → 43 base64url characters, RFC 7636's minimum length.
  const bytes = new Uint8Array(32);
  crypto.getRandomValues(bytes);
  return base64url(bytes);
}

/** Computes the S256 code challenge for a verifier. */
export async function codeChallenge(verifier: string): Promise<string> {
  const digest = await crypto.subtle.digest(
    'SHA-256',
    new TextEncoder().encode(verifier),
  );
  return base64url(new Uint8Array(digest));
}
