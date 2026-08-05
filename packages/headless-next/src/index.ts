/**
 * @file
 * Next.js adapter for the Drupal Canvas Headless SDK. This entry is
 * server-side (it reaches next/headers); the <DraftSession> client
 * component lives under `./client`, and the withCanvas() config wrapper
 * under `./config` (next.config runs outside any request scope, so it must
 * not load this entry).
 */

export { nextDraftAdapter, NEXT_DRAFT_MODE_COOKIE_NAME } from './adapter';
export {
  createDraftRouteHandlers,
  type DraftRouteHandlers,
} from './route-handlers';
export {
  createComponentMetadataHandler,
  type ComponentMetadataHandlerOptions,
} from './component-metadata';
export { toNextMetadata } from './head';
export {
  disableDraftMode,
  enableDraftMode,
  fetchPage,
  getClient,
  getDraftClient,
  getDraftConfig,
  getDraftData,
  getPublicClient,
  renewDraftSession,
} from './server';

// Core helpers and types app code commonly needs alongside the adapter.
export {
  getDraftEditorOrigin,
  getSessionToken,
  isDraftSessionExpired,
  type AccessToken,
  type DraftData,
} from '@drupal-canvas/headless';
export { isPageRedirect } from '@drupal-canvas/headless/server';
export type {
  CanvasComponentTreeElement,
  CanvasComponentTreeSlot,
  DrupalRoute,
  DrupalRouteEntity,
  Page,
  PageHead,
  PageRedirect,
  PageResult,
  DraftConfig,
} from '@drupal-canvas/headless/server';
export type {
  ComponentMetadataEntry,
  ComponentMetadataPayload,
} from '@drupal-canvas/headless/components-endpoint';
export {
  CanvasComponentTree,
  type CanvasComponentRegistry,
  type CanvasComponentTreeProps,
} from '@drupal-canvas/headless-react';
