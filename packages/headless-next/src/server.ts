import { createDraftServer } from '@drupal-canvas/headless/server';

import { nextDraftAdapter } from './adapter';

import type { DraftServer } from '@drupal-canvas/headless/server';

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
// Reference the core SDK's types to prevent duplicate client declarations.
export const getClient: DraftServer['getClient'] = server.getClient;
export const getPublicClient: DraftServer['getPublicClient'] =
  server.getPublicClient;
export const getDraftClient: DraftServer['getDraftClient'] =
  server.getDraftClient;
export const fetchEntity = server.fetchEntity;
export const fetchPage = server.fetchPage;
export const fetchComponentPreview = server.fetchComponentPreview;
