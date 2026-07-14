/**
 * @file
 * Astro adapter for the Drupal Canvas Headless SDK. This entry is
 * server-side: the per-request draft server and the data accessors app
 * code needs. The canvas() integration lives under `./integration` (the
 * Astro config runs outside any request scope), the injectable route
 * modules under `./routes/*`, and the session banner component at
 * `./DraftSession.astro`.
 */

export {
  ASTRO_DRAFT_FLAG_COOKIE_NAME,
  createAstroDraftAdapter,
  type AstroDraftContext,
} from './adapter';
export {
  fetchPage,
  getClient,
  getDraftConfig,
  getDraftData,
  getDraftServer,
  isDraftModeEnabled,
  isDraftSessionExpired,
} from './server';

// Core helpers and types app code commonly needs alongside the adapter.
export {
  getSessionToken,
  type AccessToken,
  type DraftData,
} from '@drupal-canvas/headless';
export type {
  Page,
  PageElement,
  DraftConfig,
  DraftServer,
} from '@drupal-canvas/headless/server';
export type {
  ComponentMetadataEntry,
  ComponentMetadataPayload,
} from '@drupal-canvas/headless/components-endpoint';
