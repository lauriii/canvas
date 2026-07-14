import { defineEventHandler } from 'h3';
import { isDraftSessionExpired } from '@drupal-canvas/headless';

import { getDraftConfig, getDraftData, isDraftModeEnabled } from '../session';

/**
 * What the <DraftSession> component needs to drive the client-side session
 * element, as one same-origin JSON answer.
 */
export interface DraftSessionState {
  enabled: boolean;
  tokenExpiresAt: number | null;
  expired: boolean;
  renewUrl: string | null;
  embedderOrigins: string[];
}

/**
 * The draft session state for the current request, read by the
 * <DraftSession> component (during SSR the call stays in-process). Mounted
 * at GET /api/draft/session by the module.
 *
 * Nothing here is a secret: the expiry instant, Drupal's own renew URL (a
 * signed assertion claim), and the embedder origin allowlist that is also
 * published through the CSP header. The access token never leaves the
 * httpOnly cookie.
 */
export default defineEventHandler(async (event): Promise<DraftSessionState> => {
  if (!isDraftModeEnabled(event)) {
    return {
      enabled: false,
      tokenExpiresAt: null,
      expired: false,
      renewUrl: null,
      embedderOrigins: [],
    };
  }

  const draftData = await getDraftData(event);
  return {
    enabled: true,
    tokenExpiresAt: draftData?.tokenExpiresAt ?? null,
    expired: !draftData || isDraftSessionExpired(draftData),
    renewUrl: draftData?.renewUrl ?? null,
    embedderOrigins: getDraftConfig().embedderOrigins,
  };
});
