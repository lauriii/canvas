import { createHash, randomBytes } from "node:crypto";

/**
 * PKCE pair binding assertion redemption to this server (RFC 7636 shapes).
 *
 * Renewal assertions reach this app relayed through the embedded page's
 * script context (host → postMessage → client → POST /api/draft/renew), so
 * a script injected into the app could intercept one. Drupal's grant
 * therefore refuses to redeem a renewal assertion unless the request also
 * proves possession of the running session: a `code_verifier` hashing to
 * the `code_challenge` this server registered at the previous redemption.
 * The verifier never leaves the server — it lives in the httpOnly draft
 * data cookie — so an intercepted assertion is worthless on its own.
 *
 * Every redemption registers a fresh challenge for the next one; the
 * verifier is stored alongside the session and rotated with it.
 */

/** Generates a fresh, high-entropy code verifier. */
export function generateCodeVerifier(): string {
  // 32 random bytes → 43 base64url characters, RFC 7636's minimum length.
  return randomBytes(32).toString("base64url");
}

/** Computes the S256 code challenge for a verifier. */
export function codeChallenge(verifier: string): string {
  return createHash("sha256").update(verifier).digest("base64url");
}
