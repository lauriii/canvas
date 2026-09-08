/**
 * @file
 * Helpers for consuming the Canvas Headless publish webhook: verify its
 * signature and parse its payload. A framework route handler uses these to
 * turn a publish into cache revalidation without hand-rolling HMAC or payload
 * validation. Uses the Web Crypto API, so this stays edge-runtime safe.
 */

/** One entity a publish operation touched. */
export interface PublishedEntityReference {
  entityType: string;
  id: string;
  uuid: string | null;
  langcode: string;
}

/** The Canvas Headless publish webhook payload. */
export interface PublishPayload {
  event: 'publish';
  /** The entities the publish operation saved. */
  entities: PublishedEntityReference[];
  /**
   * The cache tags the publish invalidated, indirect dependencies included.
   * Match these against the per-page cacheability tags to revalidate exactly
   * the affected pages.
   */
  tags: string[];
}

/**
 * Verifies the webhook's `X-Canvas-Signature` header against the raw body.
 *
 * The header is `sha256=<hex>`, an HMAC-SHA256 of the exact request body under
 * the shared secret configured in the site's settings. Comparison is
 * constant-time. Pass the body exactly as received (do not re-serialize a
 * parsed object, or the bytes will differ and verification will fail).
 *
 * Resolves false for a missing or malformed header rather than throwing, so a
 * route handler can answer 401 uniformly.
 */
export async function verifyPublishSignature(
  rawBody: string,
  signatureHeader: string | null | undefined,
  secret: string,
): Promise<boolean> {
  if (!signatureHeader || !signatureHeader.startsWith('sha256=')) {
    return false;
  }
  const provided = signatureHeader.slice('sha256='.length);
  const encoder = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );
  const signature = await crypto.subtle.sign(
    'HMAC',
    key,
    encoder.encode(rawBody),
  );
  const expected = Array.from(new Uint8Array(signature))
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('');
  return constantTimeEqual(provided, expected);
}

/** A constant-time string comparison, resistant to timing attacks. */
function constantTimeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) {
    return false;
  }
  let mismatch = 0;
  for (let i = 0; i < a.length; i++) {
    mismatch |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return mismatch === 0;
}

/** The outcome of reading a publish webhook request. */
export type PublishWebhookResult =
  | { ok: true; payload: PublishPayload }
  | { ok: false; status: 401 | 400 | 500; message: string };

/**
 * Reads a publish webhook request end to end: verifies the signature and
 * parses the payload, returning a result an adapter maps to its own Response.
 *
 * This is the framework-agnostic spine every adapter's revalidation handler
 * shares (Next.js route handler, Nuxt/Nitro event handler, Astro endpoint,
 * TanStack Start server route). An empty `secret` yields a 500 result, so a
 * misconfiguration is visible rather than silently unauthenticated.
 */
export async function readPublishWebhook(options: {
  rawBody: string;
  signature: string | null | undefined;
  secret: string | undefined;
}): Promise<PublishWebhookResult> {
  const { rawBody, signature, secret } = options;
  if (!secret) {
    return {
      ok: false,
      status: 500,
      message: 'The revalidation secret is not configured.',
    };
  }
  if (!(await verifyPublishSignature(rawBody, signature, secret))) {
    return { ok: false, status: 401, message: 'Invalid or missing signature.' };
  }
  try {
    return { ok: true, payload: parsePublishPayload(rawBody) };
  } catch {
    return { ok: false, status: 400, message: 'Invalid publish payload.' };
  }
}

/**
 * Parses and validates a publish webhook body.
 *
 * Throws when the body is not a well-formed publish payload, so a route
 * handler can answer 400. Verify the signature (verifyPublishSignature)
 * before trusting the result.
 */
export function parsePublishPayload(rawBody: string): PublishPayload {
  let value: unknown;
  try {
    value = JSON.parse(rawBody);
  } catch {
    throw new Error('The publish webhook body is not valid JSON.');
  }
  if (
    typeof value !== 'object' ||
    value === null ||
    (value as { event?: unknown }).event !== 'publish' ||
    !Array.isArray((value as { entities?: unknown }).entities) ||
    !Array.isArray((value as { tags?: unknown }).tags)
  ) {
    throw new Error('The publish webhook body is not a publish payload.');
  }
  return value as PublishPayload;
}
