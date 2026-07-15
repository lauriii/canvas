/**
 * @file
 * The `frame-ancestors` policy the adapters send, and its merge into
 * Content-Security-Policy header values the application may already have
 * set. Framework middleware must never replace an existing policy
 * wholesale: directives such as default-src and script-src belong to the
 * app, and discarding them would silently weaken its security posture.
 */

import { getDraftEditorOrigin } from '../draft-data';

import type { DraftData } from '../draft-data';

/**
 * The frame-ancestors source list: 'self' always, plus the exact editor
 * origin from a draft session's signed renewal URL. Without a draft
 * session, or when its URL is invalid, the policy remains 'self'-only.
 * ('none' cannot be combined with other sources, so it is not used as the
 * fallback.)
 */
export function resolveFrameAncestors(
  draftData?: Pick<DraftData, 'renewUrl'> | null,
): string {
  return ["'self'", getDraftEditorOrigin(draftData)].filter(Boolean).join(' ');
}

/** Whether any policy already defines its own frame-ancestors directive. */
export function hasFrameAncestors(
  policies: string | ReadonlyArray<string> | null | undefined,
): boolean {
  const values = Array.isArray(policies) ? policies : [policies ?? ''];
  return values.some((value) =>
    String(value)
      .split(',')
      .some((policy) =>
        policy
          .split(';')
          .some((part) => /^frame-ancestors(\s|$)/i.test(part.trim())),
      ),
  );
}

/**
 * Merges a frame-ancestors directive into existing
 * Content-Security-Policy header values, preserving every other
 * directive of every policy.
 *
 * CSP headers may repeat: multiple header fields, an array value (h3),
 * or one field carrying a comma-separated policy list all mean several
 * policies, each enforced independently. An application-owned
 * frame-ancestors directive therefore remains authoritative: when one is
 * present, this function returns the existing policies unchanged. When
 * none is present, the SDK appends its directive as one more policy.
 * Commas cannot appear inside directive values, so splitting on them is
 * safe.
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
  const policies = values
    .flatMap((value) => String(value).split(','))
    .map((policy) => policy.trim())
    .filter((policy) => policy !== '');
  if (hasFrameAncestors(policies)) {
    return policies;
  }
  return [...policies, `frame-ancestors ${frameAncestors}`];
}
