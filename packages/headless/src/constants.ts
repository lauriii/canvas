/**
 * The cookie this SDK uses to carry the draft session (entry path, resource
 * version policy, and the user-bound access token) between requests.
 */
export const DRAFT_DATA_COOKIE_NAME = 'canvas_headless_draft_data';

/**
 * The registered grant type URI for JWT bearer assertions (RFC 7523 §2.1).
 * The Canvas Headless module implements this grant: it exchanges a
 * Drupal-signed preview assertion for an access token bound to the editor
 * the assertion names.
 */
export const JWT_BEARER_GRANT_TYPE =
  'urn:ietf:params:oauth:grant-type:jwt-bearer';

/**
 * OAuth client id of the consumer the Canvas Headless module provisions at
 * install. Fixed on the Drupal side — not site configuration — so the SDK
 * carries it as a constant. A public client: there is no client secret
 * anywhere in the app; the signed preview assertion is the credential
 * (RFC 7523).
 */
export const CANVAS_HEADLESS_CLIENT_ID = 'canvas_headless';

/**
 * The host ↔ app draft-preview protocol message types.
 *
 * The embedded app cannot renew its own session — its requests to Drupal are
 * cross-site in the ancestor chain, so the editor's SameSite=Lax session
 * cookie never accompanies them. The embedding host page (the Canvas editor)
 * *does* hold that session, so renewal is a relayed conversation over
 * postMessage. These string values are the contract between the two sides:
 * the app side is implemented by the draft session state machine in this
 * package's `client` entry, the host side by @drupal-canvas/headless-host
 * (which re-exports these constants).
 */

/** Host → app: establish the current iframe document's protocol session. */
export const HEADLESS_STATUS_REQUEST_MESSAGE = 'canvas-headless:status-request';

/** App → host: draft session state, sent on load and on every change. */
export const HEADLESS_STATUS_MESSAGE = 'canvas-headless:status';

/** App → host: mint a fresh assertion (sent before the token expires). */
export const HEADLESS_RENEW_REQUEST_MESSAGE = 'canvas-headless:renew-request';

/** Host → app: a freshly minted assertion, to redeem in place. */
export const HEADLESS_ASSERTION_MESSAGE = 'canvas-headless:assertion';

/** Host → app: refresh content after Canvas persisted a new auto-save. */
export const HEADLESS_REFRESH_MESSAGE = 'canvas-headless:refresh';

/** App → host: confirms that a numbered refresh command was received. */
export const HEADLESS_REFRESH_ACK_MESSAGE = 'canvas-headless:refresh-ack';

/** Host → app: complete the trusted geometry-channel handshake. */
export const HEADLESS_GEOMETRY_REQUEST_MESSAGE =
  'canvas-headless:geometry-request';

/** App → host: one unchanged shared-library geometry snapshot. */
export const HEADLESS_GEOMETRY_MESSAGE = 'canvas-headless:geometry';

/**
 * App → host: current rendered content height, in CSS pixels. Sent on load
 * and on every ResizeObserver-detected change.
 */
export const HEADLESS_HEIGHT_MESSAGE = 'canvas-headless:height';

/** App → host: temporarily resize the iframe for viewport-height probing. */
export const HEADLESS_HEIGHT_PROBE_MESSAGE = 'canvas-headless:height-probe';

/** Host → app: the requested probe height has been applied. */
export const HEADLESS_HEIGHT_PROBE_READY_MESSAGE =
  'canvas-headless:height-probe-ready';

/** Host → app: the base height of the selected preview viewport. */
export const HEADLESS_VIEWPORT_HEIGHT_MESSAGE =
  'canvas-headless:viewport-height';
