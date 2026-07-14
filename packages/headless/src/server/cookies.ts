/**
 * A draft session cookie, shaped so that framework cookie stores accepting
 * attribute objects (for example, Next.js's ResponseCookie) can take it as
 * is.
 */
export interface DraftCookie {
  name: string;
  value: string;
  httpOnly: boolean;
  path: string;
  sameSite: 'none';
  secure: boolean;
  partitioned: boolean;
  expires?: Date;
}

/**
 * The attribute set every draft session cookie carries.
 *
 * Cookies default to SameSite=Lax, which browsers do not send in cross-site
 * iframe requests (the Drupal previewer) — draft state would silently stay
 * off inside the iframe, so the cookies are set cross-site. httpOnly and
 * path are stated explicitly rather than inherited: the token-carrying
 * cookies must not depend on other attributes happening to ride along.
 * `partitioned` (CHIPS) opts into the per-top-level-site cookie jar, which
 * is what lets browsers with third-party-cookie restrictions (Firefox,
 * Safari 26.2+, Chrome with blocking enabled) accept these cookies inside
 * the iframe. Requires a secure (HTTPS) origin.
 */
export const DRAFT_COOKIE_ATTRIBUTES = {
  httpOnly: true,
  path: '/',
  sameSite: 'none',
  secure: true,
  partitioned: true,
} as const;

/**
 * Builds a draft session cookie carrying the full cross-site attribute set.
 */
export function buildDraftCookie(name: string, value: string): DraftCookie {
  return { name, value, ...DRAFT_COOKIE_ATTRIBUTES };
}

/**
 * Builds the deletion counterpart of a draft session cookie.
 *
 * A deletion is a Set-Cookie with an expiry in the past — and the browser
 * only applies it to a cookie whose identity matches, which for CHIPS
 * cookies includes the partition. Framework-level deletions (Next.js
 * draftMode().disable(), cookieStore.delete()) emit deletions without
 * `Partitioned`, so they target an unpartitioned cookie that does not exist
 * and the real one survives — draft mode would be impossible to exit.
 * Setting the cookie to an empty value, already expired (epoch), with the
 * exact attributes buildDraftCookie() used (httpOnly is not part of cookie
 * identity but is kept in step with the set side) produces deletions that
 * match the cookies actually stored. curl-based tests cannot catch a
 * regression here: curl's cookie jar has no partitioning, so attribute-less
 * deletions work there. Verify exits in a browser.
 */
export function buildClearedDraftCookie(name: string): DraftCookie {
  return { name, value: '', expires: new Date(0), ...DRAFT_COOKIE_ATTRIBUTES };
}
