/**
 * @file
 * The `frame-ancestors` policy the adapters send, and its merge into
 * Content-Security-Policy header values the application may already have
 * set. Framework middleware must never replace an existing policy
 * wholesale: directives such as default-src and script-src belong to the
 * app, and discarding them would silently weaken its security posture.
 */

/**
 * The frame-ancestors source list: 'self' (always allowed) plus the
 * origins from DRAFT_ALLOWED_FRAME_ANCESTORS, read per call so dev
 * servers pick up .env changes. Empty variable leaves a valid 'self'-only
 * list. ('none' cannot be combined with other sources, so it is not used
 * as the fallback.)
 */
export function resolveFrameAncestors(): string {
  const extraFrameAncestors =
    process.env.DRAFT_ALLOWED_FRAME_ANCESTORS?.trim() ?? '';
  return ["'self'", extraFrameAncestors].filter(Boolean).join(' ');
}

/**
 * Merges a frame-ancestors directive into existing
 * Content-Security-Policy header values, preserving every other
 * directive of every policy.
 *
 * CSP headers may repeat: multiple header fields, an array value (h3),
 * or one field carrying a comma-separated policy list all mean several
 * policies, each enforced independently. The merge therefore works per
 * policy: any existing frame-ancestors directive is removed from each
 * (embedder policy is this SDK's to own — framing is allowed only when
 * every policy that restricts it allows it, so a leftover stale
 * directive could veto the embedder), the remaining directives are kept
 * as their own policies, and the SDK's frame-ancestors is appended as
 * one more. Commas cannot appear inside directive values, so splitting
 * on them is safe.
 *
 * Returns the policy list; single-header-line consumers join it with
 * ', ' (the standard serialization of repeated fields).
 */
export function mergeFrameAncestors(
  existingPolicies: string | ReadonlyArray<string> | null | undefined,
  frameAncestors: string,
): string[] {
  const values = Array.isArray(existingPolicies)
    ? existingPolicies
    : [existingPolicies ?? ''];
  const preserved = values
    .flatMap((value) => String(value).split(','))
    .map((policy) =>
      policy
        .split(';')
        .map((part) => part.trim())
        .filter((part) => part !== '' && !/^frame-ancestors(\s|$)/i.test(part))
        .join('; '),
    )
    .filter((policy) => policy !== '');
  return [...preserved, `frame-ancestors ${frameAncestors}`];
}
