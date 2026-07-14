/**
 * @file
 * Framework-agnostic core of the Drupal Canvas Headless SDK — the app side
 * of the Canvas Headless module's integration: draft preview sessions
 * bound to the editing user, in-place session renewal inside the Canvas
 * editor frame, and the component metadata endpoint Drupal Canvas
 * registers an app's components from. Framework adapters wire this core
 * to their routing, cookies, and build pipeline.
 *
 * This root entry is isomorphic and dependency-free: protocol constants,
 * the draft session data contract, assertion claim decoding, and the
 * session token helper. Server-side flows live under `./server`, the
 * client-side renewal state machine under `./client`, and component
 * metadata exposure under `./components-endpoint` — the subpaths keep
 * browser bundles free of Node-only code and vice versa.
 */

export {
  CANVAS_HEADLESS_CLIENT_ID,
  DRAFT_DATA_COOKIE_NAME,
  HEADLESS_ASSERTION_MESSAGE,
  HEADLESS_RENEW_REQUEST_MESSAGE,
  HEADLESS_STATUS_MESSAGE,
  JWT_BEARER_GRANT_TYPE,
} from './constants';
export {
  EXPIRY_SLACK_MS,
  isDraftSessionExpired,
  parseDraftData,
  serializeDraftData,
  type DraftData,
} from './draft-data';
export { decodeAssertionClaims } from './assertion';
export { getSessionToken, type AccessToken } from './token';
