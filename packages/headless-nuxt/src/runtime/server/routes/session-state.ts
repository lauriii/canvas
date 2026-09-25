import { defineEventHandler } from 'h3';
import {
  getDraftEditorOrigin,
  isDraftSessionExpired,
} from '@drupal-canvas/headless';

import {
  getDraftData,
  getJsonApiRuntimeConfig,
  isDraftModeEnabled,
} from '../session';

import type { JsonApiRuntimeConfig } from 'drupal-canvas/jsonapi-client';

/**
 * What the <DraftSession> component needs to drive the client-side session
 * element, as one same-origin JSON answer.
 */
export interface DraftSessionState {
  enabled: boolean;
  tokenExpiresAt: number | null;
  expired: boolean;
  renewUrl: string | null;
  editorOrigin: string | null;
  /**
   * The nonsecret JSON:API runtime configuration for browser clients: build
   * one with `createJsonApiClient()` from `drupal-canvas/jsonapi-client` to
   * read Drupal content through the application's proxy.
   */
  jsonApi: JsonApiRuntimeConfig;
}

/**
 * The draft session state for the current request, read by the
 * <DraftSession> component (during SSR the call stays in-process). Mounted
 * at GET /api/draft/session by the module.
 *
 * Nothing here is a secret: the expiry instant, Drupal's own renew URL (a
 * signed assertion claim), and its origin. The access token never leaves
 * the httpOnly cookie.
 */
export default defineEventHandler(async (event): Promise<DraftSessionState> => {
  const jsonApi = await getJsonApiRuntimeConfig(event);
  if (!isDraftModeEnabled(event)) {
    return {
      enabled: false,
      tokenExpiresAt: null,
      expired: false,
      renewUrl: null,
      editorOrigin: null,
      jsonApi,
    };
  }

  const draftData = await getDraftData(event);
  return {
    enabled: true,
    tokenExpiresAt: draftData?.tokenExpiresAt ?? null,
    expired: !draftData || isDraftSessionExpired(draftData),
    renewUrl: draftData?.renewUrl ?? null,
    editorOrigin: getDraftEditorOrigin(draftData),
    jsonApi,
  };
});
