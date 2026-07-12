/**
 * The cookie Next.js Draft Mode uses to bypass static rendering.
 */
export const DRAFT_MODE_COOKIE_NAME = "__prerender_bypass";

/**
 * The cookie this SDK uses to carry the draft session (entry path, resource
 * version policy, and the user-bound access token) between requests.
 */
export const DRAFT_DATA_COOKIE_NAME = "canvas_headless_draft_data";

/**
 * The registered grant type URI for JWT bearer assertions (RFC 7523 §2.1).
 * Drupal's canvas_headless module implements this grant: it exchanges a
 * Drupal-signed preview assertion for an access token bound to the editor
 * the assertion names.
 */
export const JWT_BEARER_GRANT_TYPE =
  "urn:ietf:params:oauth:grant-type:jwt-bearer";

/**
 * Slack applied before a draft session's access token expiry.
 *
 * Treat the token as expired this many milliseconds early so the app never
 * acts on a token that would be dead by the time the request reaches Drupal.
 * Shared by isDraftSessionExpired() (server) and the draft session client
 * (browser) so both flip to expired at the same moment.
 */
export const DRAFT_EXPIRY_SLACK_MS = 5_000;

/**
 * The host ↔ app renewal protocol message types.
 *
 * The embedded app cannot renew its own session — its requests to Drupal are
 * cross-site in the ancestor chain, so the editor's SameSite=Lax session
 * cookie never accompanies them. The embedding host page (the Canvas editor)
 * *does* hold that session, so renewal is a relayed conversation over
 * postMessage. These string values are the contract with the host; they must
 * match the HEADLESS_*_MESSAGE constants exported by @drupal-canvas/headless-host
 * (the Canvas UI's useHeadlessDraftSession hook uses them on the other side).
 * They are restated rather than imported so this example stays a standalone,
 * copy-out-able app with no workspace dependency.
 */

/** App → host: draft session state, sent on load and on every change. */
export const DRAFT_STATUS_MESSAGE = "canvas-headless:status";

/** App → host: mint a fresh assertion (sent before the token expires). */
export const DRAFT_RENEW_REQUEST_MESSAGE = "canvas-headless:renew-request";

/** Host → app: a freshly minted assertion, to redeem in place. */
export const DRAFT_ASSERTION_MESSAGE = "canvas-headless:assertion";
