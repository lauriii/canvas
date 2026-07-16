import { createDraftServer } from '@drupal-canvas/headless/server';

import { nextDraftAdapter } from './adapter';

/**
 * The module-level draft server every Next.js request shares. All state
 * lives in the request's cookies (reached through next/headers), and the
 * configuration is resolved from the environment lazily per call — nothing
 * here touches the request or the environment at import time, so builds
 * without CANVAS_SITE_URL set do not throw.
 */
const server = createDraftServer({ adapter: nextDraftAdapter });

export const getDraftData = server.getDraftData;
export const enableDraftMode = server.enableDraftMode;
export const renewDraftSession = server.renewDraftSession;
export const disableDraftMode = server.disableDraftMode;
export const getDraftConfig = server.getConfig;
export const getClient = server.getClient;
export const getPublicClient = server.getPublicClient;
export const getDraftClient = server.getDraftClient;
export const fetchPage = server.fetchPage;
