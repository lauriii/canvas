import { defineEventHandler } from 'h3';
import {
  getDraftEditorOrigin,
  isDraftSessionExpired,
} from '@drupal-canvas/headless';

import { getDraftData, isDraftModeEnabled } from '../session';

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
  if (!isDraftModeEnabled(event)) {
    return {
      enabled: false,
      tokenExpiresAt: null,
      expired: false,
      renewUrl: null,
      editorOrigin: null,
    };
  }

  const draftData = await getDraftData(event);
  return {
    enabled: true,
    tokenExpiresAt: draftData?.tokenExpiresAt ?? null,
    expired: !draftData || isDraftSessionExpired(draftData),
    renewUrl: draftData?.renewUrl ?? null,
    editorOrigin: getDraftEditorOrigin(draftData),
  };
});
