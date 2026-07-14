export interface CorsDecision {
  /**
   * Whether the request may proceed. True for allowlisted browser origins
   * and for requests without an Origin header (server-to-server callers,
   * curl — CORS is a browser concept); false only for a browser origin
   * outside the allowlist.
   */
  allowed: boolean;
  /** Response headers to attach; empty when no Origin header was sent. */
  headers: Record<string, string>;
}

/**
 * Resolves the CORS response headers for a request against the embedder
 * origin allowlist.
 *
 * The allowed origin is always echoed exactly, never `*`: the responses
 * this guards are authenticated, and the allowlist is the same one the CSP
 * frame-ancestors policy and the postMessage protocol use. No
 * Access-Control-Allow-Credentials is emitted — the endpoint uses no
 * cookies; browser callers send the assertion in an explicit header.
 */
export function resolveCorsHeaders(
  requestOrigin: string | null,
  allowedOrigins: string[],
): CorsDecision {
  if (requestOrigin === null) {
    return { allowed: true, headers: {} };
  }
  if (!allowedOrigins.includes(requestOrigin)) {
    return { allowed: false, headers: {} };
  }
  return {
    allowed: true,
    headers: {
      'Access-Control-Allow-Origin': requestOrigin,
      'Access-Control-Allow-Methods': 'GET, OPTIONS',
      'Access-Control-Allow-Headers': 'Authorization',
      'Access-Control-Max-Age': '3600',
      Vary: 'Origin',
    },
  };
}
