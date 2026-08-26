/**
 * @file
 * Server-side modules of the Drupal Canvas Headless SDK core. Everything
 * here is framework-agnostic and free of Node-only APIs — framework
 * adapters implement the DraftServerAdapter interface and mount the flows
 * as routes. Component metadata exposure, which needs the filesystem, lives
 * under `../components-endpoint` instead.
 */

export { resolveDraftConfig, type DraftConfig } from './config';
export { type DraftServerAdapter } from './adapter';
export {
  buildClearedDraftCookie,
  buildDraftCookie,
  DRAFT_COOKIE_ATTRIBUTES,
  type DraftCookie,
} from './cookies';
export {
  exchangeAssertion,
  type AssertionExchangeResult,
} from './token-exchange';
export {
  createDraftServer,
  redeemAssertion,
  type DraftServer,
  type DraftServerOptions,
  type RedemptionResult,
} from './flows';
export { getDraftClient, getPublicClient } from './json-api-client';
export { fetchPage } from './content-api';
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
} from '../page';
export {
  verifyAssertionByRedemption,
  type AssertionVerification,
} from './verify-assertion';
export {
  hasFrameAncestors,
  mergeFrameAncestors,
  resolveFrameAncestors,
} from './csp';
