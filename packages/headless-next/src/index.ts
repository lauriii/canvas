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
  fetchEntity,
  fetchPage,
  getClient,
  getDraftClient,
  getDraftConfig,
  getDraftData,
  getJsonApiRuntimeConfig,
  getPublicClient,
  handleJsonApiProxy,
  renewDraftSession,
} from './server';

// Core helpers and types app code commonly needs alongside the adapter.
export {
  getDraftEditorOrigin,
  getSessionToken,
  isDraftSessionExpired,
  isPageRedirect,
  type AccessToken,
  type CanvasComponentTreeElement,
  type CanvasComponentTreeSlot,
  type CanvasContext,
  type DrupalRoute,
  type DrupalRouteEntity,
  type DraftData,
  type EntityResult,
  type Page,
  type PageHead,
  type PageRedirect,
  type PageResult,
} from '@drupal-canvas/headless';
export type { DraftConfig } from '@drupal-canvas/headless/server';
export type { JsonApiRuntimeConfig } from 'drupal-canvas/jsonapi-client';
export type {
  ComponentMetadataEntry,
  ComponentMetadataPayload,
} from '@drupal-canvas/headless/components-endpoint';
export {
  CanvasComponentTree,
  JsonApiRuntimeProvider,
  type CanvasComponentRegistry,
  type CanvasComponentTreeProps,
  type JsonApiRuntimeProviderProps,
} from '@drupal-canvas/headless-react';
