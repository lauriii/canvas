/**
 * Decodes the claim set of a JWT assertion without verifying the signature.
 *
 * Only safe to call on an assertion Drupal's token endpoint has just
 * accepted: acceptance IS the verification (signature against Drupal's key,
 * expiry, single-use jti — all checked server-side by the jwt-bearer grant).
 * A tampered assertion never gets a token, so its claims are never read.
 * The trust binding is exact string identity — decode the same string that
 * was posted, nothing else.
 */
export function decodeAssertionClaims(
  assertion: string,
): Record<string, unknown> | null {
  const parts = assertion.split(".");
  if (parts.length !== 3) {
    return null;
  }
  try {
    const payload = Buffer.from(parts[1], "base64url").toString("utf8");
    const claims: unknown = JSON.parse(payload);
    return typeof claims === "object" && claims !== null
      ? (claims as Record<string, unknown>)
      : null;
  } catch {
    return null;
  }
}
