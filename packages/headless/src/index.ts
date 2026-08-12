/**
 * @file
 * Framework-agnostic core of the Drupal Canvas Headless SDK — the app side
 * of the Canvas Headless module's integration: draft preview sessions
 * bound to the editing user, in-place session renewal inside the Canvas
 * editor frame, and the component metadata endpoint Drupal Canvas
 * registers an app's components from. Framework adapters wire this core
 * to their routing, cookies, and build pipeline.
 *
 * This root entry is isomorphic: protocol constants, geometry validation,
 * rendered-page contracts and helpers, the draft session data contract,
 * assertion claim decoding, and the session token helper. Server-side flows
 * live under `./server`, the client-side renewal state machine under
 * `./client`, and component metadata exposure under `./components-endpoint` —
 * the subpaths keep browser bundles free of Node-only code and vice versa.
 */

export {
  CANVAS_COMPONENT_PREVIEW_QUERY,
  CANVAS_COMPONENT_PREVIEW_PATH,
  CANVAS_HEADLESS_CLIENT_ID,
  DRAFT_DATA_COOKIE_NAME,
  HEADLESS_ASSERTION_MESSAGE,
  HEADLESS_GEOMETRY_MESSAGE,
  HEADLESS_GEOMETRY_REQUEST_MESSAGE,
  HEADLESS_HEIGHT_MESSAGE,
  HEADLESS_HEIGHT_PROBE_MESSAGE,
  HEADLESS_HEIGHT_PROBE_READY_MESSAGE,
  HEADLESS_REFRESH_ACK_MESSAGE,
  HEADLESS_VIEWPORT_HEIGHT_MESSAGE,
  HEADLESS_REFRESH_MESSAGE,
  HEADLESS_RENEW_REQUEST_MESSAGE,
  HEADLESS_STATUS_MESSAGE,
  HEADLESS_STATUS_REQUEST_MESSAGE,
  JWT_BEARER_GRANT_TYPE,
} from './constants';
export {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  formatCanvasCommentMarker,
  getCanvasTemplateMarkerAttributes,
  isCanvasGeometrySnapshot,
  type CanvasGeometry,
  type CanvasMarker,
} from '@drupal-canvas/preview-geometry';
export {
  EXPIRY_SLACK_MS,
  getDraftEditorOrigin,
  isDraftSessionExpired,
  parseDraftData,
  serializeDraftData,
  type DraftData,
} from './draft-data';
export { decodeAssertionClaims } from './assertion';
export { getSessionToken, type AccessToken } from './token';
export {
  isPageRedirect,
  serializeJsonForHtml,
  type CanvasComponentTreeElement,
  type CanvasComponentTreeSlot,
  type DrupalRoute,
  type DrupalRouteEntity,
  type JsonValue,
  type Page,
  type PageHead,
  type PageHeadLink,
  type PageHeadMeta,
  type PageHeadScript,
  type PageRedirect,
  type PageResult,
} from './page';
export {
  CANVAS_COMPONENT_UUID_PROP,
  componentElementFromName,
  componentNameFromElement,
  findCanvasComponent,
  getCanvasComponentRenderData,
  isCanvasComponentTreeDraft,
  isCanvasComponentTreeEmpty,
  isCanvasComponentTreeSlotEmpty,
  normalizeCanvasComponentTreeSlot,
  reportMissingCanvasComponent,
  reportMissingCanvasComponentUuid,
  type CanvasComponentRenderData,
} from './render';
