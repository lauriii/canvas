/**
 * @file
 * The `frame-ancestors` policy the adapters send, and its merge into
 * Content-Security-Policy header values the application may already have
 * set. Framework middleware must never replace an existing policy
 * wholesale: directives such as default-src and script-src belong to the
 * app, and discarding them would silently weaken its security posture.
 */

import type { DraftData } from '../draft-data';

/**
 * CSP host sources accept DNS hostnames and IPv4 literals, not literal IPv6
 * addresses. DNS hostnames resolving to IPv6 remain supported. URL parsing
 * alone also allows policy delimiters and wildcards in hosts: reject them.
 */
const EDITOR_HOST_PATTERN =
  /^(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3}|[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*)$/;

function toEditorOrigin(value: string): string | null {
  try {
    const url = new URL(value);
    if (
      (url.protocol !== 'http:' && url.protocol !== 'https:') ||
      url.username ||
      url.password ||
      !EDITOR_HOST_PATTERN.test(url.hostname)
    ) {
      return null;
    }
    return url.origin;
  } catch {
    return null;
  }
}

/**
 * Resolve embedding policy from the server environment on each call. An
 * explicitly configured list replaces BOTH defaults, even if empty/invalid.
 * Otherwise admit the site origin and the draft session's editor origin.
 * This controls framing only, not draft authentication or postMessage trust.
 */
export function resolveFrameAncestors(
  draftData?: Pick<DraftData, 'renewUrl'> | null,
): string {
  const configured = process.env.CANVAS_EDITOR_ORIGINS;
  const values =
    configured !== undefined
      ? configured.split(/[\s,]+/)
      : [process.env.CANVAS_SITE_URL ?? '', draftData?.renewUrl ?? ''];
  const origins = values
    .map(toEditorOrigin)
    .filter((origin): origin is string => origin !== null);
  return ["'self'", ...new Set(origins)].join(' ');
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
