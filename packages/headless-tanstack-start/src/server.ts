import { isDraftSessionExpired } from '@drupal-canvas/headless';
import {
  createDraftServer,
  resolveDraftConfig,
} from '@drupal-canvas/headless/server';
import { getCookie } from '@tanstack/react-start/server';

import {
  TANSTACK_DRAFT_FLAG_COOKIE_NAME,
  tanstackDraftAdapter,
} from './adapter';

/**
 * The module-level draft server every request shares. All state lives in
 * the request's cookies (reached through the request-scoped helpers from
 * @tanstack/react-start/server), and the configuration is resolved from
 * the environment lazily per call — nothing here touches the request or
 * the environment at import time, so builds without CANVAS_SITE_URL set do
 * not throw.
 */
const server = createDraftServer({ adapter: tanstackDraftAdapter });

export const getDraftData = server.getDraftData;
export const enableDraftMode = server.enableDraftMode;
export const renewDraftSession = server.renewDraftSession;
export const disableDraftMode = server.disableDraftMode;
export const getDraftConfig = server.getConfig;
export const getClient = server.getClient;
export const getPublicClient = server.getPublicClient;
export const getDraftClient = server.getDraftClient;
export const fetchPage = server.fetchPage;

/**
 * Whether draft mode is on for this request — the flag cookie, regardless
 * of whether the session data behind it is intact or expired. This is the
 * "should the app surface draft session state at all" signal (the banner);
 * for data access, getClient() already falls back to public content when
 * the session has expired.
 */
export function isDraftModeEnabled(): boolean {
  return getCookie(TANSTACK_DRAFT_FLAG_COOKIE_NAME) === '1';
}

export { isDraftSessionExpired, resolveDraftConfig };
