/**
 * @file
 * TanStack Start adapter for the Drupal Canvas Headless SDK. This entry is
 * server-side (it reaches @tanstack/react-start/server). The pieces other
 * bundles need live in their own entries: the <DraftSession> client
 * component under `./client`, the route head translator under `./head`, the
 * canvas() Vite plugin under `./vite` (the Vite config runs outside any
 * request scope), and cspMiddleware under `./middleware` — createStart's
 * configuration is isomorphic, so a module it imports must never reach this
 * entry.
 */

export {
  tanstackDraftAdapter,
  TANSTACK_DRAFT_FLAG_COOKIE_NAME,
} from './adapter';
export {
  createDraftRouteHandlers,
  type DraftRouteHandlers,
} from './route-handlers';
export { createComponentMetadataHandlers } from './component-metadata';
export { toTanStackHead, type TanStackHead } from './head';
export {
  disableDraftMode,
  enableDraftMode,
  fetchPage,
  getClient,
  getDraftClient,
  getDraftConfig,
  getDraftData,
  getPublicClient,
  isDraftModeEnabled,
  isDraftSessionExpired,
  renewDraftSession,
} from './server';

// Core helpers and types app code commonly needs alongside the adapter.
export {
  getDraftEditorOrigin,
  getSessionToken,
  isPageRedirect,
  type AccessToken,
  type CanvasComponentTreeElement,
  type CanvasComponentTreeSlot,
  type DraftData,
  type DrupalRoute,
  type DrupalRouteEntity,
  type Page,
  type PageHead,
  type PageRedirect,
  type PageResult,
} from '@drupal-canvas/headless';
export type { DraftConfig } from '@drupal-canvas/headless/server';
export type {
  ComponentMetadataEntry,
  ComponentMetadataPayload,
} from '@drupal-canvas/headless/components-endpoint';
export {
  CanvasComponentTree,
  type CanvasComponentRegistry,
  type CanvasComponentTreeProps,
} from '@drupal-canvas/headless-react';
